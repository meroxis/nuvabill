<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Mockery\MockInterface;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Where stock decides a sale or a plan change, it is counted under a lock from the newest rows.
 * Checkouts and plan changes lock in one order (a plan change, a client, a coupon, products in id
 * order, then services) and before they read anything else. A plan change that will not count the
 * stock when it happens holds its unit while it waits. The race itself needs two connections to
 * MySQL or MariaDB: see StockRaceTest.
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

    public function test_a_plan_change_locks_both_plans_then_the_service_then_counts_before_it_reads_anything(): void
    {
        [$service, $starter, $business] = $this->serviceThatCanUpgrade();

        $statements = $this->statementsOfFirstTransaction(fn () => app(PlanChanges::class)->start($service, $business));

        $this->assertSame(['products', 'services', 'products', 'services'], $this->locksBeforeFirstRead($statements));
        $this->assertBothPlansLockedInIdOrder($statements, $starter, $business);
        $this->assertSame(PlanChange::MODE_INVOICE, PlanChange::query()->sole()->mode);
    }

    public function test_a_paid_plan_change_locks_both_plans_before_the_service_and_counts_before_it_reads_anything(): void
    {
        [$service, $starter, $business] = $this->serviceThatCanUpgrade();
        $change = app(PlanChanges::class)->start($service, $business);

        $statements = $this->statementsOfFirstTransaction(fn () => app(PlanChanges::class)->apply($change));

        $this->assertSame(['plan_changes', 'products', 'services', 'products', 'services'], $this->locksBeforeFirstRead($statements));
        $this->assertBothPlansLockedInIdOrder($statements, $starter, $business);
        $this->assertSame($business->id, $service->fresh()->product_id);
    }

    public function test_a_downgrade_that_pays_into_the_wallet_locks_the_client_before_the_plans(): void
    {
        $this->setSettings(['wallet.enabled' => true]);
        [, $starter, $business] = $this->serviceThatCanUpgrade();
        $starter->update(['stock' => 5]);
        $service = Service::factory()->create([
            'client_id' => Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'currency' => 'USD'])->id,
            'product_id' => $business->id,
            'domain' => 'down.example.test',
            'recurring_amount' => 2000,
            'next_due_date' => '2026-10-16',
        ]);
        $change = PlanChange::create([
            'service_id' => $service->id,
            'client_id' => $service->client_id,
            'from_product_id' => $business->id,
            'to_product_id' => $starter->id,
            'billing_cycle' => BillingCycle::Monthly,
            'currency' => 'USD',
            'old_amount' => 2000,
            'new_amount' => 1000,
            'difference' => -500,
            'mode' => PlanChange::MODE_NOW,
            'status' => PlanChange::STATUS_PENDING,
        ]);

        $statements = $this->statementsOfFirstTransaction(fn () => app(PlanChanges::class)->apply($change));

        // The wallet is paid last, but its client row is locked before the plans, as a checkout does.
        $this->assertSame(['plan_changes', 'clients', 'products', 'services', 'products', 'services'], $this->locksBeforeFirstRead($statements));
        $this->assertBothPlansLockedInIdOrder($statements, $starter, $business);
        $this->assertSame($starter->id, $service->fresh()->product_id);
        $this->assertSame(500, $service->client->fresh()->credit);
    }

    public function test_a_change_for_the_renewal_date_holds_the_last_one_so_a_second_client_cannot_take_it(): void
    {
        [$business, $first, $second] = $this->twoServicesThatCanMoveDown();

        $change = app(PlanChanges::class)->start($first, $business);
        $this->assertSame(PlanChange::MODE_RENEWAL, $change->mode);
        $this->assertSame(0, $business->services()->count());

        // The plan has no service yet, but its last one is taken: in the store, the cart and for plan changes.
        $this->assertSame(0, $business->stockLeft());
        $this->assertSame(0, $business->lockedStockLeft());
        $this->get(route('store.product', [$business->group, $business]))->assertOk()->assertSee('This plan is sold out right now.');
        $this->post(route('cart.store'), ['product_id' => $business->id, 'billing_cycle' => BillingCycle::Monthly->value, 'domain' => 'shop.example.test'])
            ->assertSessionHasErrors(['product_id' => 'This product is not available right now.']);
        $this->actingAs($second->client, 'web')->get(route('client.services.change-plan', $second))->assertOk()->assertDontSee('Switch to Business');

        try {
            app(PlanChanges::class)->start($second, $business);
            $this->fail('The second change should be refused.');
        } catch (RuntimeException $exception) {
            // Business is no longer offered to the client once it is sold out.
            $this->assertSame('This service cannot move to that plan.', $exception->getMessage());
        }

        $this->assertSame(1, PlanChange::query()->count());

        // On the renewal date the change moves its service; the unit it held is not counted against it.
        $this->assertSame(1, app(PlanChanges::class)->applyScheduled(Carbon::parse('2026-10-16')));
        $this->assertSame(PlanChange::STATUS_APPLIED, $change->fresh()->status);
        $this->assertSame($business->id, $first->fresh()->product_id);
        $this->assertSame(1, $business->services()->count());
        $this->assertSame(0, $business->stockLeft());

        // The applied change is counted as the service it moved, not twice.
        $business->update(['stock' => 2]);
        $this->assertSame(1, $business->stockLeft());
        $this->assertSame(1, $business->lockedStockLeft());
    }

    public function test_a_stopped_change_for_the_renewal_date_frees_the_last_one_again(): void
    {
        [$business, $first, $second] = $this->twoServicesThatCanMoveDown();
        $change = app(PlanChanges::class)->start($first, $business);
        $this->assertSame(0, $business->stockLeft());

        app(PlanChanges::class)->cancel($change);

        $this->assertSame(PlanChange::STATUS_CANCELLED, $change->fresh()->status);
        $this->assertSame(1, $business->stockLeft());
        $this->assertSame(1, $business->lockedStockLeft());
        $this->get(route('store.product', [$business->group, $business]))->assertOk()->assertDontSee('This plan is sold out right now.');

        $next = app(PlanChanges::class)->start($second, $business);
        $this->assertSame(PlanChange::MODE_RENEWAL, $next->mode);
        $this->assertSame(0, $business->stockLeft());

        // A terminated service would hold no stock on the new plan either, so its waiting change holds nothing.
        $second->update(['status' => ServiceStatus::Terminated]);
        $this->assertSame(1, $business->stockLeft());
        $this->assertSame(1, $business->lockedStockLeft());
    }

    public function test_a_paid_upgrade_counts_the_changes_waiting_for_the_renewal_date_but_not_itself(): void
    {
        $this->setSettings(['wallet.enabled' => true, 'billing.downgrade' => 'renewal']);
        [$service, , $business] = $this->serviceThatCanUpgrade();
        $upgrade = app(PlanChanges::class)->start($service, $business);

        // An upgrade counts the stock when it is paid, so it holds nothing while it waits for payment.
        $this->assertSame(PlanChange::MODE_INVOICE, $upgrade->mode);
        $this->assertSame(1, $business->stockLeft());

        $pro = Product::factory()->priced(3000)->create(['name' => 'Pro', 'upgrade_product_ids' => [$business->id]]);
        $downgrade = app(PlanChanges::class)->start($this->serviceOn($pro, 'Raz', ''), $business);
        $this->assertSame(PlanChange::MODE_RENEWAL, $downgrade->mode);
        $this->assertSame(0, $business->lockedStockLeft());

        app(PaymentRecorder::class)->record($upgrade->invoice, $upgrade->invoice->total, 'banktransfer');

        $this->assertSame(PlanChange::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame($service->product_id, $service->fresh()->product_id);
        $this->assertSame(500, $service->client->fresh()->credit, 'The upgrade paid for goes back to the wallet.');

        $this->assertSame(1, app(PlanChanges::class)->applyScheduled(Carbon::parse('2026-10-16')));
        $this->assertSame(1, $business->services()->count());
    }

    public function test_a_change_staff_made_holds_its_unit_until_it_is_applied(): void
    {
        [$service, , $business] = $this->serviceThatCanUpgrade();
        $change = app(PlanChanges::class)->start($service, $business, Admin::factory()->create(['name' => 'Mer Las']), charge: true);

        // Staff may go past the limit, so the change does not count the stock when it is paid. It holds its unit meanwhile.
        $this->assertSame(PlanChange::MODE_INVOICE, $change->mode);
        $this->assertSame(0, $business->stockLeft());
        $this->assertSame(0, $business->lockedStockLeft());

        app(PaymentRecorder::class)->record($change->invoice, $change->invoice->total, 'banktransfer');

        $this->assertSame(PlanChange::STATUS_APPLIED, $change->fresh()->status);
        $this->assertSame($business->id, $service->fresh()->product_id);
        $this->assertSame(0, $business->stockLeft());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function databaseErrors(): array
    {
        return [
            'a query that failed' => ['query'],
            'a transaction inside another that the database stopped' => ['deadlock'],
        ];
    }

    #[DataProvider('databaseErrors')]
    public function test_a_database_error_during_a_plan_change_is_not_shown_on_the_page(string $kind): void
    {
        config(['app.debug' => false]);
        Exceptions::fake();
        [$service, , $business] = $this->serviceThatCanUpgrade();
        $failed = new QueryException('mysql', 'select * from `products` where `id` = ? for update', [$business->id], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        // Laravel throws this one, with the same message, when it happens in a transaction inside another.
        $error = $kind === 'deadlock' ? new DeadlockException($failed->getMessage(), 0, $failed) : $failed;
        $this->mock(PlanChanges::class, function (MockInterface $mock) use ($error, $service): void {
            $mock->shouldReceive('enabled')->andReturnTrue();
            $mock->shouldReceive('pendingFor')->andReturn(new PlanChange(['service_id' => $service->id]));
            $mock->shouldReceive('start')->andThrow($error);
            $mock->shouldReceive('cancel')->andThrow($error);
        });
        $admin = Admin::factory()->create();

        $pages = [
            'client change' => fn () => $this->actingAs($service->client, 'web')->post(route('client.services.change-plan.store', $service), ['product_id' => $business->id]),
            'client stop' => fn () => $this->actingAs($service->client, 'web')->delete(route('client.services.change-plan.destroy', $service)),
            'staff change' => fn () => $this->actingAs($admin, 'admin')->post(route('admin.services.change-plan', $service), ['product_id' => $business->id, 'charge' => 1]),
            'staff stop' => fn () => $this->actingAs($admin, 'admin')->delete(route('admin.services.change-plan.destroy', $service)),
        ];

        foreach ($pages as $page => $request) {
            $this->flushSession();
            $response = $request();

            $this->assertSame(500, $response->getStatusCode(), $page);
            $this->assertNull(session('error'), $page);
            $this->assertStringNotContainsString('for update', (string) $response->getContent(), $page);
            $this->assertStringNotContainsString('Deadlock', (string) $response->getContent(), $page);
        }

        // Staff still find it in the log.
        Exceptions::assertReportedCount(4);
        Exceptions::assertReported($error::class);
    }

    /**
     * A client on Starter who may move up to Business, which has one left in stock.
     *
     * @return array{0: Service, 1: Product, 2: Product}
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
            'domain' => 'up.example.test',
            'recurring_amount' => 1000,
            'next_due_date' => '2026-10-16',
        ]);

        return [$service->load('product', 'client'), $starter, $business];
    }

    /**
     * Two clients on Pro who may move down to Business, which has one left in stock. A downgrade
     * waits for the renewal date, 16 Oct 2026.
     *
     * @return array{0: Product, 1: Service, 2: Service}
     */
    private function twoServicesThatCanMoveDown(): array
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $this->setSettings(['billing.downgrade' => 'renewal']);

        $business = Product::factory()->priced(2000)->create(['name' => 'Business', 'stock' => 1]);
        $pro = Product::factory()->priced(3000)->create(['name' => 'Pro', 'upgrade_product_ids' => [$business->id]]);

        return [$business, $this->serviceOn($pro, 'Mer', 'Las'), $this->serviceOn($pro, 'Raz', '')];
    }

    /**
     * An active service on the product for a new client, renewing on 16 Oct 2026.
     */
    private function serviceOn(Product $product, string $firstName, string $lastName): Service
    {
        $client = Client::factory()->create(['first_name' => $firstName, 'last_name' => $lastName, 'company_name' => null, 'currency' => 'USD']);

        return Service::factory()->create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'domain' => strtolower($firstName).'.example.test',
            'recurring_amount' => (int) $product->prices()->value('price'),
            'next_due_date' => '2026-10-16',
        ])->load('product', 'client');
    }

    /**
     * The first lock on products takes both plans in one query, in id order.
     *
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $statements
     */
    private function assertBothPlansLockedInIdOrder(array $statements, Product $from, Product $to): void
    {
        $first = collect($statements)->first(fn (array $statement): bool => preg_match('/^\s*select\b.*\bfrom\s+[`"]?products\b.*for update/is', $statement['sql']) === 1);

        $this->assertNotNull($first);
        // Integer keys are written into the query itself, not bound.
        preg_match('/\bin \(([^)]*)\)/', $first['sql'], $list);
        $this->assertEqualsCanonicalizing([$from->id, $to->id], array_map('intval', explode(',', $list[1] ?? '')));
        $this->assertMatchesRegularExpression('/order by [`"]?id[`"]? asc/i', $first['sql']);
    }

    /**
     * The statements of the first database transaction the callback begins. Locking reads carry
     * "for update", also on SQLite, which has no row locks and so leaves it out.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
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
                $statements[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
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
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $statements
     * @return list<string>
     */
    private function locksBeforeFirstRead(array $statements): array
    {
        $locks = [];

        foreach ($statements as ['sql' => $sql]) {
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
