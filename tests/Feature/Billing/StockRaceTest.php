<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\CartLine;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\SoldOut;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * The last one of a product with a stock limit goes to one buyer only, also when another checkout
 * saves its order while this one runs. On MySQL and MariaDB a transaction can read an older
 * snapshot that misses that order, so these tests open a second connection to the same database
 * and let it buy the last one at the worst moment. Two plan changes at once run the real code, the
 * second one in a process of its own. The other connection only sees saved rows, so each test saves
 * for real; TestCase then builds the database again for the next test.
 */
#[Group('mysql')]
class StockRaceTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER = 'stock_race_other';

    private Client $buyer;

    private ?bool $otherBought = null;

    private ?int $plainCountAfterSale = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs a MySQL or MariaDB test database. SQLite lets one writer in at a time and has no older snapshots, so two checkouts cannot race there; StockReservationTest covers the behaviour on SQLite.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            DB::purge(self::OTHER);

            // RefreshDatabase rolls its transaction back next. An empty one keeps it from building the
            // database a second time; TestCase builds it again, as rows here were saved for real.
            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }

        parent::tearDown();
    }

    /**
     * Each scenario saves for real, and every test here makes TestCase build the database again
     * (slow on MySQL), so related scenarios share a test.
     */
    public function test_two_checkouts_cannot_both_take_the_last_one(): void
    {
        $this->saveForReal();

        $this->aCheckoutSeesTheSaleSavedAfterItsSnapshot();
        $this->twoCheckoutsWithACouponShareOutTheLastOne();
    }

    public function test_plan_changes_at_the_same_moment_as_checkouts_and_other_plan_changes(): void
    {
        $this->saveForReal();

        $this->aPlanChangeCannotStartForTheLastOneSoldAfterItsSnapshot();
        $this->aPaidPlanChangeStopsWhenTheLastOneSoldAfterItsSnapshot();
        $this->aPlanChangeTheDatabaseStoppedIsTriedAgain();
        $this->twoPlanChangesInOppositeDirectionsBothStartAtTheFirstTry();
    }

    public function test_on_mariadb_with_snapshot_isolation_the_checkout_is_tried_again_and_refused(): void
    {
        try {
            DB::statement('SET SESSION innodb_snapshot_isolation = ON');
        } catch (QueryException) {
            $this->markTestSkipped('Only MariaDB has snapshot isolation, which newer releases turn on by default.');
        }

        $this->saveForReal();
        $product = Product::factory()->priced()->withoutDomain()->create(['stock' => 1]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD']);
        $lines = $this->cartLines($product);
        $this->otherCheckoutBuysDuringNextTransaction($product);
        $tries = 0;
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$tries): void {
            $tries += $event->connectionName === DB::getDefaultConnection() ? 1 : 0;
        });

        // The locking count after the older snapshot fails with "Record has changed since last read";
        // the whole order is rolled back and made again, and then sees the sale.
        $this->expectException(SoldOut::class);

        try {
            app(OrderPlacer::class)->place($client, $lines);
        } finally {
            $this->assertSame(2, $tries);
            $this->assertSame(1, $this->servicesHoldingStock($product));
            $this->assertSame(0, Order::query()->count());
        }
    }

    /**
     * The order's transaction has read something (its snapshot) when the other checkout saves the
     * last one. The stock count under the lock still sees it, where a plain count would not.
     */
    private function aCheckoutSeesTheSaleSavedAfterItsSnapshot(): void
    {
        $product = Product::factory()->priced()->withoutDomain()->create(['stock' => 1, 'name' => 'Last Server']);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD']);
        $lines = $this->cartLines($product);
        $orders = Order::query()->count();

        $this->otherCheckoutBuysDuringNextTransaction($product);

        try {
            app(OrderPlacer::class)->place($client, $lines);
            $this->fail('The order should not be made.');
        } catch (SoldOut $exception) {
            $this->assertSame('Last Server is sold out. Remove it from your cart.', $exception->getMessage());
        }

        $this->assertTrue($this->otherBought);

        if (DB::selectOne('select @@transaction_isolation as level')->level === 'REPEATABLE-READ') {
            $this->assertSame(1, $this->plainCountAfterSale, 'A plain count in the order read the older snapshot and missed the sale.');
        }

        $this->assertSame(1, $this->servicesHoldingStock($product));
        $this->assertSame($orders, Order::query()->count());
    }

    /**
     * The coupon is checked with plain reads (has this client ordered before?), the moment that
     * used to fix the snapshot before the stock was counted. The other checkout tries to buy the
     * last one right after that read: one of the two gets it, never both.
     */
    private function twoCheckoutsWithACouponShareOutTheLastOne(): void
    {
        $product = Product::factory()->priced()->withoutDomain()->create(['stock' => 1]);
        $coupon = Coupon::factory()->create(['code' => 'WELCOME', 'new_clients_only' => true]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD']);
        $cart = app(Cart::class);
        $cart->clear();
        $cart->add($product, BillingCycle::Monthly, null);
        $cart->setCoupon($coupon->code);
        $checked = $cart->coupon('USD', $client);
        $lines = $cart->lines('USD', $client);
        $this->assertGreaterThan(0, $lines->first()->discount);

        $this->otherCheckoutBuysDuringNextTransaction($product, '/^\s*select\b(?!.*\bfor update\b)/is');

        try {
            app(OrderPlacer::class)->place($client, $lines, coupon: $checked);
            $ordered = true;
        } catch (SoldOut) {
            $ordered = false;
        }

        $this->assertNotNull($this->otherBought, 'The other checkout ran.');
        $this->assertNotSame($ordered, $this->otherBought, 'Exactly one of the two checkouts got the last one.');
        $this->assertSame(1, $this->servicesHoldingStock($product));
    }

    /**
     * A client asks for a plan whose last one another checkout saves after the plan change's
     * transaction read something: the change is refused, with no invoice.
     */
    private function aPlanChangeCannotStartForTheLastOneSoldAfterItsSnapshot(): void
    {
        [$service, $business] = $this->serviceThatCanUpgrade();
        $invoices = Invoice::query()->count();

        $this->otherCheckoutBuysDuringNextTransaction($business);

        $refused = null;

        try {
            app(PlanChanges::class)->start($service, $business);
        } catch (RuntimeException $exception) {
            $refused = $exception->getMessage();
        }

        $this->assertSame('This plan is sold out.', $refused);
        $this->assertTrue($this->otherBought);
        $this->assertSame(0, PlanChange::query()->where('service_id', $service->id)->count());
        $this->assertSame($invoices, Invoice::query()->count());
        $this->assertSame(1, $this->servicesHoldingStock($business));
    }

    /**
     * An upgrade is paid, and another checkout saves the last one of the plan right after the
     * change is locked to apply it: the change stops and its money goes back to the wallet.
     */
    private function aPaidPlanChangeStopsWhenTheLastOneSoldAfterItsSnapshot(): void
    {
        [$service, $business] = $this->serviceThatCanUpgrade();
        $change = app(PlanChanges::class)->start($service, $business);

        $this->otherCheckoutBuysDuringNextTransaction($business, '/\bfrom\s+[`"]?plan_changes\b.*\bfor update\b/is');
        app(PaymentRecorder::class)->record($change->invoice, $change->invoice->total, 'banktransfer');

        $this->assertTrue($this->otherBought);
        $this->assertSame(PlanChange::STATUS_CANCELLED, $change->fresh()->status);
        $this->assertSame($service->product_id, $service->fresh()->product_id);
        $this->assertSame(1, $this->servicesHoldingStock($business));
        $this->assertSame(500, $service->client->fresh()->credit, 'The upgrade paid for goes back to the wallet.');
    }

    /**
     * Two sales can still lock each other out through the gaps between saved rows. The database then
     * stops one of them; a plan change it stopped is rolled back and tried again, and makes one
     * change and one invoice.
     */
    private function aPlanChangeTheDatabaseStoppedIsTriedAgain(): void
    {
        [$service, $business] = $this->serviceThatCanUpgrade();
        $tries = $this->countTries();
        $stopped = false;

        DB::listen(function (QueryExecuted $query) use (&$stopped): void {
            if (! $stopped && $query->connection->transactionLevel() > 0 && preg_match('/\bfrom\s+[`"]?services\b.*\bfor update\b/is', $query->sql) === 1) {
                $stopped = true;

                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        $change = app(PlanChanges::class)->start($service, $business);

        $this->assertTrue($stopped);
        $this->assertSame(2, $tries->count);
        $this->assertSame(PlanChange::MODE_INVOICE, $change->mode);
        $this->assertSame(1, PlanChange::query()->where('service_id', $service->id)->count());
        $this->assertSame(1, Invoice::query()->whereHas('items', fn ($query) => $query->where('service_id', $service->id))->count());
    }

    /**
     * Two clients change plan at the same moment in opposite directions: Small to Large here, and
     * Large to Small in a process of its own that holds its locks for a moment. Both plans have a
     * stock limit, so each change counts the other's plan. Both start at the first try: this one
     * waits for the plans the other one holds, instead of holding a service the other one needs.
     */
    private function twoPlanChangesInOppositeDirectionsBothStartAtTheFirstTry(): void
    {
        // The other process runs on the real clock and reads the settings saved here.
        $this->travelBack();
        $this->setSettings(['billing.downgrade' => 'renewal']);

        $small = Product::factory()->priced(1000)->create(['name' => 'Small', 'stock' => 5]);
        $large = Product::factory()->priced(2000)->create(['name' => 'Large', 'stock' => 5]);
        $small->update(['upgrade_product_ids' => [$large->id]]);
        $large->update(['upgrade_product_ids' => [$small->id]]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD']);
        $up = Service::factory()->create(['client_id' => $client->id, 'product_id' => $small->id, 'domain' => 'up.example.test', 'recurring_amount' => 1000, 'next_due_date' => today()->addDays(15)]);
        $down = Service::factory()->create(['client_id' => $this->buyer->id, 'product_id' => $large->id, 'domain' => 'down.example.test', 'recurring_amount' => 2000, 'next_due_date' => today()->addDays(15)]);

        $other = $this->startPlanChangeInAnotherProcess($down, $small, pauseMs: 2000);
        $tries = $this->countTries();
        $waited = null;

        DB::listen(function (QueryExecuted $query) use (&$waited): void {
            if ($waited === null && preg_match('/\bfrom\s+[`"]?products\b.*\bfor update\b/is', $query->sql) === 1) {
                $waited = $query->time;
            }
        });

        $change = app(PlanChanges::class)->start($up->load('product', 'client'), $large);
        $result = $other->wait();
        $lines = preg_split('/\R/', trim($result->output()));

        $this->assertSame(['started' => true, 'mode' => PlanChange::MODE_RENEWAL, 'tries' => 1], json_decode((string) end($lines), true), 'The other change: '.$result->errorOutput());
        $this->assertSame(PlanChange::MODE_INVOICE, $change->mode);
        $this->assertSame(1, $tries->count, 'This change started at the first try.');
        $this->assertGreaterThan(1000, $waited, 'This change waited for the plans the other change held.');
        $this->assertSame(2, PlanChange::query()->whereIn('service_id', [$up->id, $down->id])->count());
    }

    /**
     * Start a client's plan change in a process of its own (tests/Fixtures/billing), with the same
     * test database. Returns once that change holds its locks; it keeps them for the pause.
     */
    private function startPlanChangeInAnotherProcess(Service $service, Product $product, int $pauseMs): InvokedProcess
    {
        $config = DB::connection()->getConfig();
        $process = Process::path(base_path())
            ->env([
                'APP_ENV' => 'testing',
                'CACHE_STORE' => 'array',
                'MAIL_MAILER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'DB_URL' => '',
                'DB_CONNECTION' => DB::getDefaultConnection(),
                'DB_HOST' => (string) $config['host'],
                'DB_PORT' => (string) $config['port'],
                'DB_DATABASE' => (string) $config['database'],
                'DB_USERNAME' => (string) $config['username'],
                'DB_PASSWORD' => (string) $config['password'],
            ])
            ->timeout(60)
            ->start([PHP_BINARY, base_path('tests/Fixtures/billing/start-plan-change.php'), (string) $service->id, (string) $product->id, (string) $pauseMs]);

        $locked = false;
        $process->waitUntil(function (string $type, string $output) use (&$locked): bool {
            return $locked = $locked || str_contains($output, 'locked');
        });

        $this->assertTrue($locked, 'The other plan change got its locks: '.$process->errorOutput().$process->output());

        return $process;
    }

    /**
     * Counts how many times this connection begins its next transaction (not the ones inside it)
     * until one is saved: 1 when the first try is saved, 2 when the database stopped the first.
     *
     * @return object{count: int}
     */
    private function countTries(): object
    {
        $tries = new class
        {
            public int $count = 0;

            public bool $saved = false;
        };
        $default = DB::getDefaultConnection();

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use ($tries, $default): void {
            $tries->count += ! $tries->saved && $event->connectionName === $default && $event->connection->transactionLevel() === 1 ? 1 : 0;
        });
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($tries, $default): void {
            $tries->saved = $tries->saved || ($event->connectionName === $default && $event->connection->transactionLevel() === 0);
        });

        return $tries;
    }

    /**
     * Leave the test's own transaction, which would hide every row from the other connection. From
     * here on rows are saved for real.
     */
    private function saveForReal(): void
    {
        DB::rollBack();

        $this->buyer = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'currency' => 'USD']);
    }

    /**
     * A client on Starter who may move up to Business, which has one left in stock.
     *
     * @return array{0: Service, 1: Product}
     */
    private function serviceThatCanUpgrade(): array
    {
        // 15 of the 30 days in this period are left, so the upgrade costs 5.00.
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $this->setSettings(['wallet.enabled' => true]);

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
     * @return Collection<int, CartLine>
     */
    private function cartLines(Product $product): Collection
    {
        $cart = app(Cart::class);
        $cart->clear();
        $cart->add($product, BillingCycle::Monthly, null);

        return $cart->lines('USD');
    }

    /**
     * Once, inside this connection's next transaction: as it begins, or right after its first query
     * that matches the pattern, it reads something (which fixes its snapshot on MySQL and MariaDB),
     * then the other checkout buys the last one of the product.
     */
    private function otherCheckoutBuysDuringNextTransaction(Product $product, ?string $pattern = null): void
    {
        [$this->otherBought, $this->plainCountAfterSale] = [null, null];
        $armed = true;
        $default = DB::getDefaultConnection();
        $buy = function () use (&$armed, $product): void {
            $armed = false;
            DB::table('clients')->count();
            $this->otherBought = $this->otherCheckoutBuys($product);
            $this->plainCountAfterSale = $product->stockLeft();
        };

        if ($pattern === null) {
            Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed, $buy, $default): void {
                if ($armed && $event->connectionName === $default) {
                    $buy();
                }
            });

            return;
        }

        DB::listen(function (QueryExecuted $query) use (&$armed, $buy, $default, $pattern): void {
            if ($armed && $query->connectionName === $default && $query->connection->transactionLevel() > 0 && preg_match($pattern, $query->sql) === 1) {
                $buy();
            }
        });
    }

    /**
     * Another checkout, on its own connection, buys the last one: it locks the product as every
     * checkout does, saves a service for it and commits. False when it waited a second for the
     * product's lock in vain, because this connection holds it.
     */
    private function otherCheckoutBuys(Product $product): bool
    {
        $other = $this->otherConnection();
        $other->beginTransaction();

        try {
            $other->table('products')->where('id', $product->id)->lockForUpdate()->first();
            $other->table('services')->insert([
                'client_id' => $this->buyer->id,
                'product_id' => $product->id,
                'status' => ServiceStatus::Pending->value,
                'billing_cycle' => BillingCycle::Monthly->value,
                'currency' => 'USD',
                'registration_date' => today()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $other->commit();

            return true;
        } catch (QueryException $exception) {
            $other->rollBack();
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());

            return false;
        }
    }

    /**
     * A second connection to the same test database, which gives up after a second when it waits
     * for a lock this connection holds, so a test never hangs.
     */
    private function otherConnection(): Connection
    {
        if (config('database.connections.'.self::OTHER) === null) {
            config(['database.connections.'.self::OTHER => config('database.connections.'.DB::getDefaultConnection())]);
            DB::connection(self::OTHER)->statement('SET SESSION innodb_lock_wait_timeout = 1');
        }

        return DB::connection(self::OTHER);
    }

    private function servicesHoldingStock(Product $product): int
    {
        return Service::query()->where('product_id', $product->id)->whereNotIn('status', [ServiceStatus::Terminated, ServiceStatus::Cancelled])->count();
    }
}
