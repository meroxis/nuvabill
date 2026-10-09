<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\OrderPlacer;
use App\Billing\PlanChanges;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use PDOException;
use Tests\TestCase;

/**
 * Where stock decides a sale or a plan change, it is counted under a lock from the newest rows.
 * Checkouts and plan changes lock in one order (products before services) and before they read
 * anything else. The race itself needs two connections to MySQL or MariaDB: see StockRaceTest.
 */
class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stock_count_for_a_sale_counts_the_saved_services_that_hold_stock(): void
    {
        $product = Product::factory()->create(['stock' => 4]);
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '']);

        foreach (ServiceStatus::cases() as $status) {
            Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'status' => $status]);
        }

        // Pending, active, suspended and fraud hold stock; terminated and cancelled do not.
        $this->assertSame(0, $product->lockedStockLeft());
        $this->assertSame(0, $product->stockLeft());

        // The saved stock counts, not the copy in memory.
        $copy = Product::query()->findOrFail($product->id);
        $product->update(['stock' => 6]);
        $this->assertSame(2, $copy->lockedStockLeft());

        $product->update(['stock' => null]);
        $this->assertNull($copy->lockedStockLeft());

        // A product deleted meanwhile has nothing left to sell.
        $gone = Product::factory()->create(['stock' => 5]);
        $copy = Product::query()->findOrFail($gone->id);
        $gone->delete();
        $this->assertSame(0, $copy->lockedStockLeft());
    }

    public function test_a_checkout_locks_client_coupon_products_and_services_before_it_reads_anything(): void
    {
        $product = Product::factory()->priced(1000)->withoutDomain()->create(['stock' => 3]);
        $coupon = Coupon::factory()->create(['code' => 'WELCOME', 'new_clients_only' => true]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD']);

        $cart = app(Cart::class);
        $cart->add($product, BillingCycle::Monthly, null);
        $cart->setCoupon($coupon->code);
        $checked = $cart->coupon('USD', $client);
        $lines = $cart->lines('USD', $client);

        $statements = $this->statementsOfFirstTransaction(fn () => app(OrderPlacer::class)->place($client, $lines, coupon: $checked));

        $this->assertSame(['clients', 'coupons', 'products', 'products', 'services'], $this->locksBeforeFirstRead($statements));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_plan_change_locks_the_new_plan_then_the_service_then_counts_before_it_reads_anything(): void
    {
        [$service, $business] = $this->serviceThatCanUpgrade();

        $statements = $this->statementsOfFirstTransaction(fn () => app(PlanChanges::class)->start($service, $business));

        $this->assertSame(['products', 'services', 'products', 'services'], $this->locksBeforeFirstRead($statements));
        $this->assertSame(PlanChange::MODE_INVOICE, PlanChange::query()->sole()->mode);
    }

    public function test_a_paid_plan_change_locks_the_new_plan_before_the_service_and_counts_before_it_reads_anything(): void
    {
        [$service, $business] = $this->serviceThatCanUpgrade();
        $change = app(PlanChanges::class)->start($service, $business);

        $statements = $this->statementsOfFirstTransaction(fn () => app(PlanChanges::class)->apply($change));

        $this->assertSame(['plan_changes', 'products', 'services', 'products', 'services'], $this->locksBeforeFirstRead($statements));
        $this->assertSame($business->id, $service->fresh()->product_id);
    }

    public function test_a_database_error_during_a_plan_change_is_not_shown_to_the_client(): void
    {
        [$service, $business] = $this->serviceThatCanUpgrade();
        $error = new QueryException('mysql', 'select * from `products` where `id` = ? for update', [$business->id], new PDOException('Lock wait timeout exceeded; try restarting transaction'));
        $this->mock(PlanChanges::class, function (MockInterface $mock) use ($error): void {
            $mock->shouldReceive('enabled')->andReturnTrue();
            $mock->shouldReceive('start')->andThrow($error);
        });

        $this->actingAs($service->client)
            ->post(route('client.services.change-plan.store', $service), ['product_id' => $business->id])
            ->assertServerError()
            ->assertSessionMissing('error');
    }

    /**
     * A client on Starter who may move up to Business, which has one left in stock.
     *
     * @return array{0: Service, 1: Product}
     */
    private function serviceThatCanUpgrade(): array
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));

        $starter = Product::factory()->priced(1000)->create(['name' => 'Starter']);
        $business = Product::factory()->priced(2000)->create(['name' => 'Business', 'stock' => 1]);
        $starter->update(['upgrade_product_ids' => [$business->id]]);

        $service = Service::factory()->create([
            'client_id' => Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD'])->id,
            'product_id' => $starter->id,
            'recurring_amount' => 1000,
            'next_due_date' => '2026-10-16',
        ]);

        return [$service->load('product', 'client'), $business];
    }

    /**
     * The statements of the first database transaction the callback begins. Locking reads carry
     * "for update", also on SQLite, which has no row locks and so leaves it out.
     *
     * @return list<string>
     */
    private function statementsOfFirstTransaction(callable $callback): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    return $value === true ? '/* for update */' : '';
                }
            });
        }

        $statements = [];
        $began = 0;
        Event::listen(TransactionBeginning::class, function () use (&$began): void {
            $began++;
        });
        DB::listen(function (QueryExecuted $query) use (&$statements, &$began): void {
            if ($began === 1) {
                $statements[] = $query->sql;
            }
        });

        try {
            $callback();
        } finally {
            $connection->useDefaultQueryGrammar();
        }

        return $statements;
    }

    /**
     * The tables read under a lock, in order, until the first plain read.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function locksBeforeFirstRead(array $statements): array
    {
        $locks = [];

        foreach ($statements as $sql) {
            if (! str_starts_with(strtolower(ltrim($sql)), 'select')) {
                continue;
            }

            if (! str_contains(strtolower($sql), 'for update')) {
                break;
            }

            preg_match('/\bfrom\s+[`"]?(\w+)/i', $sql, $table);
            $locks[] = $table[1] ?? $sql;
        }

        return $locks;
    }
}
