<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\Cart;
use App\Billing\InvoiceManager;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
