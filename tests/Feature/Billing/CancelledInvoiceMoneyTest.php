<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\InvoiceManager;
use App\Billing\OrderPlacer;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\Wallet;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Extensions\Gateways\PaymentResult;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Money paid on an invoice that is then cancelled goes back to the client's wallet, and a payment
 * that reaches a closed invoice goes there too, so the client never loses it.
 */
class CancelledInvoiceMoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->setSettings(['wallet.enabled' => true, 'wallet.auto_apply' => true, 'currency.rates' => []]);
    }

    private function merLas(array $attributes = []): Client
    {
        return Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD'] + $attributes);
    }

    private function placeOrder(Client $client, int $price): Order
    {
        $cart = app(Cart::class);
        $cart->clear();
        $cart->add(Product::factory()->priced($price)->create(), BillingCycle::Monthly, 'merlas.test');

        return app(OrderPlacer::class)->place($client, $cart->lines('USD'));
    }

    public function test_cancelling_a_part_paid_invoice_puts_the_wallet_money_back(): void
    {
        $client = $this->merLas(['credit' => 2000]);
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 10000]]);
        app(Wallet::class)->applyAutomatically($invoice);
        $this->assertSame(2000, $invoice->fresh()->amount_paid);
        $this->assertSame(0, $client->fresh()->credit);

        app(InvoiceManager::class)->cancel($invoice);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(2000, $client->fresh()->credit);
        $this->assertSame(0, (int) CreditTransaction::query()->sum('amount'));
        $this->assertDatabaseHas('credit_transactions', ['client_id' => $client->id, 'invoice_id' => $invoice->id, 'amount' => 2000]);
        $this->assertSame(0, (int) $invoice->transactions()->sum('amount'), 'The wallet payment and its refund add up to nothing.');

        app(InvoiceManager::class)->cancel($invoice);
        $this->assertSame(2000, $client->fresh()->credit, 'Cancelling again gives nothing back twice.');
    }

    public function test_cancelling_never_overwrites_a_payment_that_just_arrived(): void
    {
        $client = $this->merLas();
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        $stale = Invoice::query()->findOrFail($invoice->id);

        app(PaymentRecorder::class)->record($invoice, 1000, 'stripe', 'pi_just_now');
        app(InvoiceManager::class)->cancel($stale);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1000, $invoice->fresh()->amount_paid);
        $this->assertSame(0, $client->fresh()->credit);
    }

    public function test_staff_cancel_a_part_paid_invoice_and_the_payment_goes_to_the_wallet(): void
    {
        $this->signInAdmin();
        $client = $this->merLas();
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 2000]]);

        $this->post(route('admin.invoices.payments.store', $invoice), ['amount' => '5', 'method' => 'banktransfer', 'reference' => 'P1', 'paid_at' => today()->toDateString()])
            ->assertSessionHas('status');
        $this->post(route('admin.invoices.cancel', $invoice))
            ->assertSessionHas('status', 'Invoice cancelled. The $5.00 paid on it went back to the client\'s wallet.');

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(500, $client->fresh()->credit);
        $this->assertSame(-500, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->assertSame(500, (int) Transaction::query()->revenue()->sum('amount'), 'The bank money was still received: it now sits in the wallet.');

        $this->post(route('admin.invoices.cancel', $invoice))->assertRedirect();
        $this->assertSame(500, $client->fresh()->credit, 'A second click gives nothing back twice.');
    }

    public function test_staff_cannot_cancel_an_invoice_paid_while_the_page_was_open(): void
    {
        $this->signInAdmin();
        $invoice = app(InvoiceManager::class)->create($this->merLas(), [['description' => 'Hosting', 'amount' => 1000]]);
        app(PaymentRecorder::class)->record($invoice, 1000, 'stripe', 'pi_meanwhile');

        $this->post(route('admin.invoices.cancel', $invoice))->assertSessionHas('error', 'Only unpaid invoices can be cancelled.');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_money_that_cannot_go_back_to_the_wallet_stops_the_cancel(): void
    {
        $this->signInAdmin();
        $client = $this->merLas();
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 2000]], currency: 'GBP');
        app(PaymentRecorder::class)->record($invoice, 500, 'banktransfer', 'bank-gbp');

        $this->post(route('admin.invoices.cancel', $invoice))->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(500, $invoice->fresh()->amount_paid);
        $this->assertSame(0, $client->fresh()->credit);
        $this->assertSame(0, Transaction::query()->where('type', 'refund')->count());

        // With a rate it goes back, converted: £5.00 at 1 GBP = 1.25 USD.
        $this->setSettings(['billing.currency' => 'USD', 'currency.rates' => ['GBP' => 0.8]]);
        $this->post(route('admin.invoices.cancel', $invoice))->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(625, $client->fresh()->credit);
    }

    public function test_cancelling_an_order_returns_the_wallet_money_paid_on_its_invoice(): void
    {
        $client = $this->merLas(['credit' => 2000]);
        $order = $this->placeOrder($client, 5000);
        $invoice = $order->invoice->fresh();
        $this->assertSame(2000, $invoice->amount_paid);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $client->fresh()->credit);

        $this->signInAdmin();
        $this->post(route('admin.orders.cancel', $order))
            ->assertSessionHas('status', 'Order cancelled. The $20.00 paid on its invoice went back to the client\'s wallet.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid);
        $this->assertSame(2000, $client->fresh()->credit);
        $this->assertDatabaseHas('credit_transactions', ['client_id' => $client->id, 'invoice_id' => $invoice->id, 'amount' => 2000]);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(ServiceStatus::Cancelled, $order->services()->sole()->status);
    }

    public function test_a_refused_order_cancel_leaves_the_order_and_its_services_alone(): void
    {
        $client = $this->merLas();
        $order = $this->placeOrder($client, 5000);
        app(PaymentRecorder::class)->record($order->invoice, 1000, 'banktransfer', 'bank-part');
        // The wallet is in another currency now (after an import, for example), with no exchange rate.
        $client->forceFill(['currency' => 'GBP'])->save();

        $this->signInAdmin();
        $this->post(route('admin.orders.cancel', $order))->assertSessionHas('error');

        $this->assertSame(InvoiceStatus::Unpaid, $order->invoice->fresh()->status);
        $this->assertSame(1000, $order->invoice->fresh()->amount_paid);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(ServiceStatus::Pending, $order->services()->sole()->status);
    }

    public function test_orders_can_only_be_accepted_or_cancelled_while_pending(): void
    {
        $this->signInAdmin();

        // A cancelled order is not set up from a page that was left open.
        $cancelled = $this->placeOrder($this->merLas(), 1000);
        $this->post(route('admin.orders.cancel', $cancelled))->assertSessionHas('status');
        $this->post(route('admin.orders.accept', $cancelled))->assertSessionHas('error');
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(ServiceStatus::Cancelled, $cancelled->services()->sole()->status);

        // An accepted order is not cancelled later: its services run, so its invoice stays.
        $accepted = $this->placeOrder(Client::factory()->create(['first_name' => 'Raz', 'last_name' => '']), 1000);
        $this->post(route('admin.orders.accept', $accepted))->assertSessionHas('status');
        $this->assertSame(ServiceStatus::Active, $accepted->services()->sole()->status);
        $this->post(route('admin.orders.cancel', $accepted))->assertSessionHas('error');
        $this->assertSame(OrderStatus::Active, $accepted->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $accepted->invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_gateway_payment_on_a_cancelled_invoice_goes_to_the_wallet_once(): void
    {
        $client = $this->merLas();
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 899]]);
        app(InvoiceManager::class)->cancel($invoice);

        $payments = app(PaymentRecorder::class);
        $payments->recordGatewayResult(new PaymentResult($invoice->id, 899, 'USD', 'fib_late_1'), 'fib');
        $payments->recordGatewayResult(new PaymentResult($invoice->id, 899, 'USD', 'fib_late_1'), 'fib');

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(899, $client->fresh()->credit, 'Once, though the gateway reported it twice.');
        $this->assertDatabaseHas('credit_transactions', ['client_id' => $client->id, 'invoice_id' => $invoice->id, 'amount' => 899]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.on_closed_invoice', 'subject_id' => $invoice->id]);
        $this->assertSame(0, (int) $invoice->transactions()->sum('amount'));
    }

    public function test_a_late_payment_on_a_refunded_invoice_goes_to_the_wallet_or_waits_for_staff(): void
    {
        $client = $this->merLas();
        $refunded = Invoice::factory()->for($client)->create(['status' => InvoiceStatus::Refunded, 'total' => 1000]);
        app(PaymentRecorder::class)->record($refunded, 1000, 'stripe', 'pi_late');

        $this->assertSame(InvoiceStatus::Refunded, $refunded->fresh()->status);
        $this->assertSame(1000, $client->fresh()->credit);

        // Without an exchange rate for the wallet, staff settle it by hand.
        $pounds = Invoice::factory()->for($client)->create(['status' => InvoiceStatus::Cancelled, 'currency' => 'GBP']);
        app(PaymentRecorder::class)->record($pounds, 500, 'stripe', 'pi_late_gbp');

        $this->assertSame(1000, $client->fresh()->credit);
        $this->assertSame(500, $pounds->fresh()->amount_paid);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.on_closed_invoice', 'subject_id' => $pounds->id]);
    }

    /**
     * @return array{0: Service, 1: Product, 2: Product}
     */
    private function serviceOnStarter(int $credit, string $currency = 'USD'): array
    {
        // 15 of the 30 days in this period are left.
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $starter = Product::factory()->priced(1000, currency: $currency)->create(['name' => 'Starter']);
        $business = Product::factory()->priced(2000, currency: $currency)->create(['name' => 'Business']);
        $starter->update(['upgrade_product_ids' => [$business->id]]);

        $service = Service::factory()->create([
            'client_id' => $this->merLas(['credit' => $credit])->id,
            'product_id' => $starter->id,
            'currency' => $currency,
            'recurring_amount' => 1000,
            'next_due_date' => '2026-10-16',
            'domain' => 'shop.merlas.test',
        ]);

        return [$service, $starter, $business];
    }

    public function test_stopping_a_part_paid_upgrade_puts_the_wallet_money_back(): void
    {
        [$service, $starter, $business] = $this->serviceOnStarter(300);
        $client = $service->client;

        $this->actingAs($client, 'web')->post(route('client.services.change-plan.store', $service), ['product_id' => $business->id])->assertRedirect();
        $invoice = Invoice::query()->sole();
        $this->assertSame(500, $invoice->total);
        $this->assertSame(300, $invoice->amount_paid, 'The wallet paid part of it at once.');
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $client->fresh()->credit);

        $this->delete(route('client.services.change-plan.destroy', $service))->assertSessionHas('status');

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(300, $client->fresh()->credit);
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame($starter->id, $service->fresh()->product_id);
    }

    public function test_a_stop_that_cannot_give_the_money_back_leaves_the_change_waiting(): void
    {
        [$service, , $business] = $this->serviceOnStarter(0, 'EUR');
        $change = app(PlanChanges::class)->start($service->load('product', 'client'), $business);
        app(PaymentRecorder::class)->record($change->invoice, 100, 'banktransfer', 'bank-eur-part');

        $this->actingAs($service->client, 'web')->delete(route('client.services.change-plan.destroy', $service))
            ->assertSessionHas('error', 'Part of the invoice for this change is already paid. Contact us to stop the change.');

        $this->assertSame(InvoiceStatus::Unpaid, $change->invoice->fresh()->status);
        $this->assertSame(PlanChange::STATUS_PENDING, $change->fresh()->status);
    }

    public function test_stopping_a_change_whose_invoice_was_just_paid_keeps_the_new_plan(): void
    {
        [$service, , $business] = $this->serviceOnStarter(0);
        $change = app(PlanChanges::class)->start($service->load('product', 'client'), $business);
        $stale = PlanChange::query()->with('invoice')->findOrFail($change->id);

        app(PaymentRecorder::class)->record($change->invoice, 500, 'stripe', 'pi_upgrade');

        try {
            app(PlanChanges::class)->cancel($stale);
            $this->fail('A paid change cannot stop.');
        } catch (RuntimeException) {
        }

        $this->assertSame(InvoiceStatus::Paid, $change->invoice->fresh()->status);
        $this->assertSame(PlanChange::STATUS_APPLIED, $change->fresh()->status);
        $this->assertSame($business->id, $service->fresh()->product_id);
    }
}
