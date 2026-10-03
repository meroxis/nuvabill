<?php

namespace Tests\Feature\Gateways;

use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => self::WEBHOOK_SECRET]);
    }

    public function test_paying_redirects_to_stripe_checkout(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);

        $client = Client::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 1399]);

        $this->actingAs($client, 'web')
            ->get(route('client.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Stripe');

        $this->post(route('client.invoices.pay', $invoice), ['gateway' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

        [$request] = Http::recorded()->first();

        $this->assertInstanceOf(Request::class, $request);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $request->url());
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer sk_test_123'));
        $this->assertEquals(1399, $request['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', $request['line_items'][0]['price_data']['currency']);
        $this->assertEquals($invoice->id, $request['metadata']['invoice_id']);
        $this->assertSame($this->siteMarker(), $request['metadata']['nuvabill_site']);
        $this->assertSame($this->siteMarker(), $request['payment_intent_data']['metadata']['nuvabill_site']);
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $request['success_url']);
    }

    public function test_a_payment_link_session_naming_an_invoice_is_ignored(): void
    {
        $invoice = Invoice::factory()->create(['total' => 1399]);

        // A buyer can put any client_reference_id on a Stripe Payment Link.
        $this->postWebhook($this->sessionCompletedEvent($invoice, 1399, metadata: []))->assertOk();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_a_session_from_another_site_on_the_same_stripe_account_is_ignored(): void
    {
        $client = Client::factory()->create(['auto_pay' => false]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 1399]);
        $payload = json_decode($this->sessionCompletedEvent($invoice, 1399, metadata: ['invoice_id' => (string) $invoice->id, 'save' => '1', 'nuvabill_site' => 'another-site']), true);
        $payload['data']['object']['customer'] = 'cus_stranger';
        $payload['data']['object']['payment_intent'] = ['id' => 'pi_stranger', 'payment_method' => ['id' => 'pm_stranger', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2028]]];

        $this->postWebhook((string) json_encode($payload))->assertOk();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, PaymentMethod::query()->count());
        $this->assertFalse($client->fresh()->auto_pay);
    }

    public function test_a_session_not_started_here_does_not_pay_the_invoice_on_the_return_page(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 1399]);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_link*' => Http::response(json_decode($this->sessionCompletedEvent($invoice, 1399, metadata: []), true)['data']['object'])]);

        $this->actingAs($client, 'web')
            ->get(route('client.invoices.return', [$invoice, 'stripe']).'?session_id=cs_link')
            ->assertRedirect(route('client.invoices.show', $invoice))
            ->assertSessionHas('status', 'Thanks! We will mark the invoice paid as soon as the payment is confirmed.');

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_a_session_started_before_the_update_counts_when_it_returns_to_this_invoice(): void
    {
        $invoice = Invoice::factory()->create(['total' => 1399]);
        $other = Invoice::factory()->create(['total' => 1399]);

        // No site marker: only a session that sends the client back to this invoice here counts.
        $elsewhere = json_decode($this->sessionCompletedEvent($other, 1399, metadata: ['invoice_id' => (string) $other->id]), true);
        $elsewhere['data']['object']['success_url'] = 'https://another-site.example.test/client/invoices/'.$other->id.'/return/stripe?session_id={CHECKOUT_SESSION_ID}';
        $this->postWebhook((string) json_encode($elsewhere))->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $other->fresh()->status);

        $here = json_decode($this->sessionCompletedEvent($invoice, 1399, metadata: ['invoice_id' => (string) $invoice->id]), true);
        $here['data']['object']['success_url'] = route('client.invoices.return', [$invoice, 'stripe']).'?session_id={CHECKOUT_SESSION_ID}';
        $this->postWebhook((string) json_encode($here))->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_a_delayed_payment_is_recorded_when_stripe_confirms_it(): void
    {
        $invoice = Invoice::factory()->create(['total' => 1399]);

        // For example SEPA Direct Debit: the session completes before the money arrives.
        $this->postWebhook($this->sessionCompletedEvent($invoice, 1399, paymentStatus: 'unpaid'))->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        $this->postWebhook($this->sessionCompletedEvent($invoice, 1399, type: 'checkout.session.async_payment_succeeded'))->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(['pi_test_42'], $invoice->transactions()->pluck('reference')->all());
    }

    public function test_the_webhook_setup_text_names_every_event_stripe_must_send(): void
    {
        $help = app(ExtensionManager::class)->gateway('stripe')->settingsFields()['webhook_secret']['help'];

        $this->assertStringContainsString('checkout.session.completed', $help);
        $this->assertStringContainsString('checkout.session.async_payment_succeeded', $help);
        $this->assertStringContainsString('payment_intent.succeeded', $help);
    }

    public function test_an_exchange_rate_never_makes_stripe_charge_another_currency(): void
    {
        // Stripe sends the invoice's own currency and amount, so it must never stand in for a conversion.
        $this->setSettings(['currency.rates' => ['KWD' => 0.31]]);

        $this->assertNull(app(ExtensionManager::class)->gateway('stripe')->chargeCurrencyFor('KWD'));
        $this->assertFalse(app(ExtensionManager::class)->activeGateways('KWD')->has('stripe'));
        $this->assertSame('EUR', app(ExtensionManager::class)->gateway('stripe')->chargeCurrencyFor('eur'));
    }

    public function test_a_signed_webhook_marks_the_invoice_paid_once(): void
    {
        $invoice = Invoice::factory()->create(['total' => 1399]);
        $payload = $this->sessionCompletedEvent($invoice, 1399);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame('pi_test_42', $invoice->transactions()->first()->reference);
    }

    public function test_a_webhook_with_a_bad_signature_is_rejected(): void
    {
        $invoice = Invoice::factory()->create(['total' => 1399]);

        $this->call('POST', route('webhooks.gateway', 'stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=forged',
            'CONTENT_TYPE' => 'application/json',
        ], $this->sessionCompletedEvent($invoice, 1399))->assertStatus(400);

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_an_old_webhook_is_rejected_to_stop_replays(): void
    {
        $invoice = Invoice::factory()->create(['total' => 1399]);

        $this->postWebhook($this->sessionCompletedEvent($invoice, 1399), time() - 3600)->assertStatus(400);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_a_payment_in_the_wrong_currency_is_not_recorded(): void
    {
        $invoice = Invoice::factory()->create(['total' => 1399]);

        $this->postWebhook($this->sessionCompletedEvent($invoice, 1399, 'eur'))->assertOk();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_webhooks_for_disabled_gateways_are_refused(): void
    {
        $this->enableGateway('paypal');
        app(ExtensionManager::class)->saveSettings('stripe', [], false);

        $this->postJson(route('webhooks.gateway', 'stripe'), [])->assertNotFound();
        $this->postJson(route('webhooks.gateway', 'nope'), [])->assertNotFound();
    }

    /**
     * @param  array<string, string>|null  $metadata  What this site puts on its sessions when null.
     */
    private function sessionCompletedEvent(Invoice $invoice, int $amount, string $currency = 'usd', ?array $metadata = null, string $paymentStatus = 'paid', string $type = 'checkout.session.completed'): string
    {
        return (string) json_encode([
            'id' => 'evt_1',
            'type' => $type,
            'data' => ['object' => [
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'amount_total' => $amount,
                'currency' => $currency,
                'payment_status' => $paymentStatus,
                'payment_intent' => 'pi_test_42',
                'client_reference_id' => (string) $invoice->id,
                'metadata' => $metadata ?? ['invoice_id' => (string) $invoice->id, 'nuvabill_site' => $this->siteMarker()],
            ]],
        ]);
    }

    /**
     * The marker this site puts on its Stripe sessions, made from the app key.
     */
    private function siteMarker(): string
    {
        return substr(hash_hmac('sha256', 'nuvabill-stripe-checkout', (string) config('app.key')), 0, 32);
    }

    private function postWebhook(string $payload, ?int $timestamp = null): TestResponse
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return $this->call('POST', route('webhooks.gateway', 'stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
