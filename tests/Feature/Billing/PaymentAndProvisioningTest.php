<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Jobs\ProvisionService;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentAndProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function placeCpanelOrder(Server $server, string $email = 'raz@example.test'): Invoice
    {
        $client = Client::factory()->create(['email' => $email]);
        $product = Product::factory()->cpanel($server)->priced(899)->create();

        $cart = app(Cart::class);
        $cart->add($product, BillingCycle::Monthly, 'razstudio.com');

        return app(OrderPlacer::class)->place($client, $cart->lines('USD'))->invoice;
    }

    public function test_paying_the_first_invoice_creates_the_cpanel_account(): void
    {
        Http::fake([
            '*/json-api/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']]),
        ]);

        $server = Server::factory()->create(['hostname' => 'whm.example.test']);
        $invoice = $this->placeCpanelOrder($server);

        $admin = $this->signInAdmin();
        $this->post(route('admin.invoices.payments.store', $invoice), [
            'amount' => '8.99',
            'method' => 'banktransfer',
            'reference' => 'BANK-123',
            'paid_at' => today()->toDateString(),
        ])->assertSessionHas('status');

        $invoice->refresh();
        $service = $invoice->items->first()->service->fresh();

        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertNotNull($service->username);
        $this->assertNotNull($service->password);
        $this->assertTrue($service->next_due_date->equalTo(today()->addMonthNoOverflow()));
        $this->assertSame(OrderStatus::Active, $service->order->status);

        Http::assertSent(function (Request $request) use ($service): bool {
            return str_starts_with($request->url(), 'https://whm.example.test:2087/json-api/createacct')
                && $request['domain'] === 'razstudio.com'
                && $request['plan'] === 'starter'
                && $request['username'] === $service->username
                && $request->header('Authorization')[0] === 'whm root:TESTTOKEN123';
        });

        $this->assertDatabaseHas('activity_logs', ['action' => 'service.created', 'subject_id' => $service->id]);
        $this->assertNotNull($admin);
    }

    public function test_a_failed_account_creation_leaves_the_service_pending_and_logs_why(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'Package does not exist']])]);

        $invoice = $this->placeCpanelOrder(Server::factory()->create());
        app(PaymentRecorder::class)->record($invoice, 899, 'stripe', 'pi_123');

        $service = $invoice->items->first()->service->fresh();

        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'service.module_failed', 'subject_id' => $service->id]);
        $this->get(route('admin.dashboard'))->assertRedirect();
        $this->signInAdmin();
        $this->get(route('admin.dashboard'))->assertSee('waiting to be set up');
    }

    public function test_a_queued_setup_does_not_bring_back_a_service_staff_cancelled(): void
    {
        Queue::fake();
        Http::fake(['*/json-api/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']])]);
        $invoice = $this->placeCpanelOrder(Server::factory()->create());
        $order = Order::query()->sole();
        app(PaymentRecorder::class)->record($invoice, 899, 'stripe', 'pi_queued');
        Queue::assertPushed(ProvisionService::class);
        $queued = $order->services()->sole();

        $this->signInAdmin();
        $this->post(route('admin.orders.cancel', $order))->assertRedirect();
        (new ProvisionService($queued))->handle(app(Provisioner::class));

        $this->assertSame(ServiceStatus::Cancelled, $queued->fresh()->status);
        Http::assertNothingSent();

        // A service still waiting is set up as usual.
        $waiting = $this->placeCpanelOrder(Server::factory()->create(), 'mer@example.test')->items->first()->service;
        (new ProvisionService($waiting))->handle(app(Provisioner::class));
        $this->assertSame(ServiceStatus::Active, $waiting->fresh()->status);
    }

    public function test_a_new_account_never_goes_to_a_server_that_is_turned_off(): void
    {
        Http::fake(['*/json-api/createacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']])]);
        $off = Server::factory()->create(['hostname' => 'old.example.test', 'is_active' => false]);
        $invoice = $this->placeCpanelOrder($off);
        $service = $invoice->items->first()->service;
        $this->assertSame($off->id, $service->server_id);

        app(PaymentRecorder::class)->record($invoice, 899, 'stripe', 'pi_off');

        // No other server: the service waits, and staff see why.
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'service.module_failed', 'subject_id' => $service->id]);
        Http::assertNothingSent();

        // With an active server for the same module, the account goes there instead.
        Server::factory()->create(['hostname' => 'new.example.test']);
        $this->assertTrue(app(Provisioner::class)->create($service->fresh())->success);

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame('new.example.test', $service->fresh()->server->hostname);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'old.example.test'));
    }

    public function test_the_same_gateway_payment_is_only_recorded_once(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        $invoice = $this->placeCpanelOrder(Server::factory()->create());
        $recorder = app(PaymentRecorder::class);

        $recorder->record($invoice, 899, 'stripe', 'pi_same');
        $recorder->record($invoice, 899, 'stripe', 'pi_same');

        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(899, $invoice->fresh()->amount_paid);
    }

    public function test_a_partial_payment_keeps_the_invoice_unpaid(): void
    {
        $invoice = $this->placeCpanelOrder(Server::factory()->create());

        app(PaymentRecorder::class)->record($invoice, 500, 'banktransfer');

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(399, $invoice->fresh()->balance());
    }

    public function test_an_overpayment_goes_to_account_credit(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        $invoice = $this->placeCpanelOrder(Server::factory()->create());

        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer');

        $this->assertSame(101, $invoice->client->fresh()->credit);
    }

    public function test_staff_can_suspend_and_unsuspend_on_the_server(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        $server = Server::factory()->create();
        $service = Service::factory()->create([
            'product_id' => Product::factory()->cpanel($server)->create()->id,
            'server_id' => $server->id,
            'username' => 'razacc',
        ]);

        $this->signInAdmin();

        $this->post(route('admin.services.module', [$service, 'suspend']), ['reason' => 'Abuse report'])->assertSessionHas('status');
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame('Abuse report', $service->fresh()->suspension_reason);

        $this->post(route('admin.services.module', [$service, 'unsuspend']))->assertSessionHas('status');
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/json-api/suspendacct') && $request['user'] === 'razacc' && $request['reason'] === 'Abuse report');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/json-api/unsuspendacct'));
    }

    public function test_cpanel_usernames_follow_cpanel_rules(): void
    {
        $module = app(ExtensionManager::class)->serverModule('cpanel');

        foreach (['testsite.com', '123abc.net', 'my-very-long-domain-name.org', 'x.io'] as $domain) {
            $username = $module->makeUsername($domain);

            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{1,15}$/', $username);
            $this->assertStringStartsNotWith('test', $username);
        }
    }
}
