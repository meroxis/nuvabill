<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\Cart;
use App\Billing\InvoiceManager;
use App\Billing\OrderCanceller;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Orders nobody pays for do not hold stock or server room for ever.
 */
class UnpaidOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_unpaid_new_orders_are_cancelled_and_free_their_stock(): void
    {
        $product = Product::factory()->priced()->withoutDomain()->create(['stock' => 1]);
        $order = $this->checkout($product);

        $this->assertFalse($product->fresh()->isInStock());

        app(DailyAutomation::class)->run(today()->addDays(6));
        $this->assertSame(ServiceStatus::Pending, $order->services()->sole()->status, 'Still inside the 7 days.');

        $summary = app(DailyAutomation::class)->run(today()->addDays(7));

        $this->assertSame(1, $summary['orders_cancelled']);
        $this->assertSame(ServiceStatus::Cancelled, $order->services()->sole()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, $order->invoice->fresh()->status);
        $this->assertTrue($product->fresh()->isInStock());
    }

    public function test_paid_part_paid_or_paying_orders_are_kept(): void
    {
        $product = Product::factory()->priced(1000)->withoutDomain()->create();
        $partPaid = $this->checkout($product);
        app(PaymentRecorder::class)->record($partPaid->invoice, 100, 'banktransfer');

        $charging = $this->checkout($product);
        $charging->invoice->forceFill(['autopay_pending' => ['reference' => 'pi_waiting']])->save();

        $paid = $this->checkout($product);
        app(PaymentRecorder::class)->record($paid->invoice, 1000, 'banktransfer');

        // Set up when it was ordered: it stays, with its invoice, for the overdue steps to handle.
        $setUp = $this->checkout($product);
        $setUp->services()->update(['status' => ServiceStatus::Active]);

        app(DailyAutomation::class)->run(today()->addDays(30));

        $this->assertSame(OrderStatus::Pending, $setUp->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $setUp->invoice->fresh()->status);

        $this->assertSame(OrderStatus::Pending, $partPaid->fresh()->status);
        $this->assertSame(ServiceStatus::Pending, $partPaid->services()->sole()->status);
        $this->assertSame(OrderStatus::Pending, $charging->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $charging->invoice->fresh()->status);
        $this->assertSame(OrderStatus::Active, $paid->fresh()->status);
    }

    public function test_staff_can_turn_it_off(): void
    {
        $this->signInAdmin();
        $this->put(route('admin.settings.update'), [
            'company_name' => 'YourHost',
            'company_email' => 'billing@host.test',
            'currency' => 'USD',
            'renewal_days_before' => 7,
            'payment_terms_days' => 7,
            'suspend_days' => 5,
            'terminate_days' => 30,
            'cancel_unpaid_days' => 0,
            'accent' => '#0B7A70',
            'theme' => 'nova',
            'locale_default' => 'en',
        ])->assertSessionHas('status');

        $this->assertSame(0, setting('orders.cancel_unpaid_days'));
        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Cancel unpaid new orders after (days)');

        $order = $this->checkout(Product::factory()->priced()->withoutDomain()->create());
        app(DailyAutomation::class)->run(today()->addDays(60));

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_an_order_whose_invoice_staff_cancelled_gives_its_stock_back(): void
    {
        $product = Product::factory()->priced()->withoutDomain()->create(['stock' => 1]);
        $order = $this->checkout($product);

        app(InvoiceManager::class)->cancel($order->invoice);
        app(DailyAutomation::class)->run(today()->addDays(7));

        $this->assertSame(ServiceStatus::Cancelled, $order->services()->sole()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertTrue($product->fresh()->isInStock());
    }

    public function test_unpaid_orders_do_not_use_up_a_servers_accounts(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']])]);
        $server = Server::factory()->create(['hostname' => 'full.example.test', 'max_accounts' => 1]);
        $product = Product::factory()->cpanel($server)->priced(899)->create();

        // Left unpaid.
        $this->placeOrder($product, 'raz@example.test');
        $this->assertSame(0, $server->fresh()->accountsCount());

        $paid = $this->placeOrder($product, 'mer@example.test');
        app(PaymentRecorder::class)->record($paid->invoice, 899, 'stripe', 'pi_cap');

        $service = $paid->services()->sole();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame($server->id, $service->server_id);
        $this->assertSame(1, $server->fresh()->accountsCount());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'full.example.test'));
    }

    public function test_the_nightly_log_says_how_many_unpaid_orders_were_cancelled(): void
    {
        $order = $this->checkout(Product::factory()->priced()->withoutDomain()->create());
        $this->travel(7)->days();

        $this->artisan('nuvabill:cron')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertStringContainsString('unpaid orders cancelled: 1', ActivityLog::query()->where('action', 'automation.run')->sole()->description);
    }

    public function test_the_server_list_counts_the_accounts_its_limit_counts(): void
    {
        $server = Server::factory()->create(['name' => 'Web 1', 'max_accounts' => 5]);
        $product = Product::factory()->cpanel($server)->priced(899)->create();

        // Left unpaid, so it takes no room yet.
        $this->placeOrder($product, 'raz@example.test');
        Service::factory()->create(['product_id' => $product->id, 'server_id' => $server->id]);

        $this->assertSame(1, $server->fresh()->accountsCount());

        $this->signInAdmin();
        $this->get(route('admin.servers.index'))->assertOk()->assertSee('1 / 5')->assertDontSee('2 / 5');
    }

    public function test_an_order_staff_accept_while_the_nightly_run_starts_is_not_cancelled_under_it(): void
    {
        $server = Server::factory()->create(['hostname' => 'slow.example.test']);
        $order = $this->placeOrder(Product::factory()->cpanel($server)->priced(899)->create(), 'raz@example.test');
        $this->travel(8)->days();

        // The nightly run starts while the server is still making the account.
        $summary = null;
        Http::fake(['*/json-api/createacct*' => function () use (&$summary) {
            $summary = app(DailyAutomation::class)->run();

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']]);
        }]);

        $this->signInAdmin();
        $this->post(route('admin.orders.accept', $order))->assertSessionHas('status');

        $this->assertSame(0, $summary['orders_cancelled'], 'Left for the next run while it is being set up.');
        $service = $order->services()->sole();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertNotNull($service->next_due_date, 'It still renews.');
        $this->assertSame(OrderStatus::Active, $order->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $order->invoice->fresh()->status, 'Still to be paid, and chased as overdue.');

        $this->assertSame(0, app(DailyAutomation::class)->run()['orders_cancelled']);
    }

    public function test_an_order_with_a_service_being_set_up_is_left_for_the_next_run(): void
    {
        $order = $this->checkout(Product::factory()->priced()->withoutDomain()->create());
        // For example staff clicked "Create account" on the service page.
        $setUp = Cache::lock(Provisioner::LOCK_PREFIX.$order->services()->sole()->id, 900);
        $this->assertTrue($setUp->get());

        $this->assertSame(0, app(DailyAutomation::class)->run(today()->addDays(8))['orders_cancelled']);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(ServiceStatus::Pending, $order->services()->sole()->status);

        $setUp->release();

        $this->assertSame(1, app(DailyAutomation::class)->run(today()->addDays(9))['orders_cancelled']);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_a_service_cancelled_while_its_account_was_made_stays_cancelled(): void
    {
        $server = Server::factory()->create(['hostname' => 'slow.example.test']);
        $order = $this->placeOrder(Product::factory()->cpanel($server)->priced(899)->create(), 'raz@example.test');

        // The order is cancelled while the server makes the account, by a path that did not wait for
        // the locks (for example after the cache was cleared).
        Http::fake([
            '*/json-api/createacct*' => function () use ($order) {
                app(OrderCanceller::class)->cancel($order->fresh());

                return Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']]);
            },
            '*/json-api/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account removed']]),
        ]);

        $this->signInAdmin();
        $this->post(route('admin.orders.accept', $order))->assertSessionHas('error');

        $service = $order->services()->sole();
        $this->assertSame(ServiceStatus::Cancelled, $service->status, 'Not brought back as a free service that never renews.');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertStringContainsString('so it was not activated', ActivityLog::query()->where('action', 'service.module_failed')->sole()->description);
        $this->assertSame(1, ActivityLog::query()->where('action', 'service.account_removed')->count(), 'The account made for the cancelled order is removed again.');
    }

    public function test_orders_placed_before_the_update_are_never_cancelled_unpaid(): void
    {
        $product = Product::factory()->priced()->withoutDomain()->create();
        $older = $this->checkout($product);
        $this->assertNull(setting('orders.cancel_unpaid_from'), 'A new site cancels every unpaid order.');

        // The update that brings this in sees the orders already there.
        (require database_path('migrations/2027_07_02_000108_orders_keep_older_unpaid_orders.php'))->up();
        $this->assertNotNull(setting('orders.cancel_unpaid_from'));

        $this->travel(1)->minutes();
        $newer = $this->checkout($product);

        $summary = app(DailyAutomation::class)->run(today()->addDays(30));

        $this->assertSame(1, $summary['orders_cancelled']);
        $this->assertSame(OrderStatus::Pending, $older->fresh()->status, 'Its client may still pay by bank transfer.');
        $this->assertSame(InvoiceStatus::Unpaid, $older->invoice->fresh()->status);
        $this->assertSame(ServiceStatus::Pending, $older->services()->sole()->status);
        $this->assertSame(OrderStatus::Cancelled, $newer->fresh()->status);

        // Running the update again changes nothing.
        $saved = setting('orders.cancel_unpaid_from');
        $this->travel(1)->days();
        (require database_path('migrations/2027_07_02_000108_orders_keep_older_unpaid_orders.php'))->up();
        $this->assertSame($saved, setting('orders.cancel_unpaid_from'));
    }

    public function test_a_new_site_with_no_orders_saves_no_start_time(): void
    {
        (require database_path('migrations/2027_07_02_000108_orders_keep_older_unpaid_orders.php'))->up();

        $this->assertNull(setting('orders.cancel_unpaid_from'));
        $this->assertFalse(Setting::query()->where('key', 'orders.cancel_unpaid_from')->exists());
    }

    private function checkout(Product $product): Order
    {
        $this->actingAs(Client::factory()->create(), 'web');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly']);
        $this->post(route('checkout.store'))->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }

    private function placeOrder(Product $product, string $email): Order
    {
        $cart = app(Cart::class);
        $cart->clear();
        $cart->add($product, BillingCycle::Monthly, 'razstudio.com');

        return app(OrderPlacer::class)->place(Client::factory()->create(['email' => $email]), $cart->lines('USD'));
    }
}
