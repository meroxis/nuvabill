<?php

namespace Tests\Feature\Gateways;

use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Client;
use App\Models\Invoice;
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
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $request['success_url']);
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

    private function sessionCompletedEvent(Invoice $invoice, int $amount, string $currency = 'usd'): string
    {
        return (string) json_encode([
            'id' => 'evt_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_1',
                'object' => 'checkout.session',
                'amount_total' => $amount,
                'currency' => $currency,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_42',
                'client_reference_id' => (string) $invoice->id,
                'metadata' => ['invoice_id' => (string) $invoice->id],
            ]],
        ]);
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
