<?php

namespace Tests\Feature\Gateways;

use App\Billing\ExchangeRates;
use App\Billing\SavedMethods;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PayPalGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'client-id', 'client_secret' => 'client-secret']);
    }

    public function test_a_client_pays_with_paypal_and_the_capture_is_recorded(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 899]);

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER123',
                'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123']],
            ], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123/capture' => Http::response([
                'id' => 'ORDER123',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'custom_id' => $this->customId($invoice),
                    'payments' => ['captures' => [[
                        'id' => 'CAPTURE9',
                        'status' => 'COMPLETED',
                        'amount' => ['currency_code' => 'USD', 'value' => '8.99'],
                        'seller_receivable_breakdown' => ['paypal_fee' => ['currency_code' => 'USD', 'value' => '0.56']],
                    ]]],
                ]],
            ], 201),
        ]);

        $this->actingAs($client, 'web')
            ->post(route('client.invoices.pay', $invoice), ['gateway' => 'paypal'])
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER123');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
            && $request['purchase_units'][0]['amount']['value'] === '8.99'
            && $request['purchase_units'][0]['custom_id'] === $invoice->id.':'.$this->siteMarker());

        $this->get(route('client.invoices.return', [$invoice, 'paypal']).'?token=ORDER123&PayerID=XYZ')
            ->assertRedirect(route('client.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('CAPTURE9', $invoice->transactions()->first()->reference);
        $this->assertSame(56, $invoice->transactions()->first()->fee);
    }

    public function test_paypal_is_hidden_for_currencies_it_does_not_support(): void
    {
        $client = Client::factory()->create(['currency' => 'IQD']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'IQD']);

        $this->actingAs($client, 'web')
            ->get(route('client.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('value="paypal"', false);
    }

    public function test_an_exchange_rate_does_not_offer_paypal_for_a_currency_it_cannot_charge(): void
    {
        // PayPal never converts: with a rate it would still send dinar, which PayPal refuses.
        app(ExchangeRates::class)->save(['IQD' => 1310]);
        Http::fake();
        $client = Client::factory()->create(['currency' => 'IQD']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'IQD', 'total' => 1310000]);

        $this->assertFalse(app(ExtensionManager::class)->activeGateways('IQD')->has('paypal'));
        $this->assertFalse(app(SavedMethods::class)->gateways('IQD')->has('paypal'));
        $this->assertTrue(app(ExtensionManager::class)->activeGateways('USD')->has('paypal'));

        $this->actingAs($client, 'web')
            ->get(route('client.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('value="paypal"', false)
            ->assertDontSee('You pay');

        $this->post(route('client.invoices.pay', $invoice), ['gateway' => 'paypal'])->assertSessionHas('error', 'Choose one of the payment methods shown.');
        $this->post(route('client.account.payment-methods.store', 'paypal'))->assertSessionHas('error', 'Choose one of the payment methods shown.');
        Http::assertNothingSent();
    }

    public function test_the_currencies_page_does_not_tell_staff_a_rate_makes_paypal_work(): void
    {
        $this->signInAdmin();
        $this->setSettings(['billing.currency' => 'IQD', 'currency.rates' => ['USD' => 0.00076]]);

        $this->get(route('admin.settings.currencies.edit'))
            ->assertOk()
            ->assertSee('PayPal does not take IQD, so clients will not see it.')
            ->assertDontSee('PayPal is ready')
            ->assertDontSee('Add a rate for a currency it takes');

        // A gateway that does convert is still told to get a rate, and is ready once it has one.
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);
        $this->setSettings(['billing.currency' => 'USD', 'currency.rates' => []]);
        $this->get(route('admin.settings.currencies.edit'))->assertOk()
            ->assertSee('Wayl does not take USD. Add a rate for a currency it takes, or clients will not see it.');

        $this->setSettings(['currency.rates' => ['IQD' => 1310]]);
        $this->get(route('admin.settings.currencies.edit'))->assertOk()
            ->assertSee('Wayl is ready: it charges the converted amount.');
    }

    public function test_an_unverified_paypal_webhook_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'token-1'])]);
        $invoice = Invoice::factory()->create(['total' => 899]);

        $this->postJson(route('webhooks.gateway', 'paypal'), [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP1', 'status' => 'COMPLETED', 'custom_id' => (string) $invoice->id, 'amount' => ['currency_code' => 'USD', 'value' => '8.99']],
        ])->assertStatus(400);

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_a_capture_from_another_site_on_the_same_paypal_app_is_ignored(): void
    {
        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'client-id', 'client_secret' => 'client-secret', 'webhook_id' => 'WH-1']);
        $this->fakeVerifiedWebhooks();
        $invoice = Invoice::factory()->create(['total' => 899]);

        // Another site numbers its invoices from 1 too, and PayPal sends its payments here as well.
        $otherSite = substr(hash_hmac('sha256', 'nuvabill-paypal-site', 'another-app-key'), 0, 24);
        $this->postCaptureCompleted($invoice->id.':'.$otherSite)->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());

        $this->postCaptureCompleted($this->customId($invoice))->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(['CAP1'], $invoice->transactions()->pluck('reference')->all());
    }

    public function test_a_capture_of_an_order_made_before_the_site_marker_counts_only_for_a_while(): void
    {
        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'client-id', 'client_secret' => 'client-secret', 'webhook_id' => 'WH-1']);
        $this->fakeVerifiedWebhooks();
        $late = Invoice::factory()->create(['total' => 899]);
        $early = Invoice::factory()->create(['total' => 899]);

        Carbon::setTestNow('2026-11-15 00:00:00');
        $this->postCaptureCompleted((string) $late->id)->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $late->fresh()->status);

        Carbon::setTestNow('2026-11-14 23:59:00');
        $this->postCaptureCompleted((string) $early->id)->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $early->fresh()->status);
    }

    public function test_an_order_paid_on_another_site_does_not_pay_the_invoice_here(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 899]);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER9/capture' => Http::response([
                'id' => 'ORDER9',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'custom_id' => $invoice->id.':'.substr(hash_hmac('sha256', 'nuvabill-paypal-site', 'another-app-key'), 0, 24),
                    'payments' => ['captures' => [['id' => 'CAPTURE9', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '8.99']]]],
                ]],
            ], 201),
        ]);

        $this->actingAs($client, 'web')->get(route('client.invoices.return', [$invoice, 'paypal']).'?token=ORDER9&PayerID=XYZ');

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(0, Transaction::query()->count());
    }

    /**
     * The custom_id this site puts on its PayPal orders, which PayPal sends back.
     */
    private function customId(Invoice $invoice): string
    {
        return $invoice->id.':'.$this->siteMarker();
    }

    private function siteMarker(): string
    {
        return substr(hash_hmac('sha256', 'nuvabill-paypal-site', (string) config('app.key')), 0, 24);
    }

    private function fakeVerifiedWebhooks(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
    }

    private function postCaptureCompleted(string $customId): TestResponse
    {
        return $this->postJson(route('webhooks.gateway', 'paypal'), [
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => 'CAP1', 'status' => 'COMPLETED', 'custom_id' => $customId, 'amount' => ['currency_code' => 'USD', 'value' => '8.99']],
        ], [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'PAYPAL-TRANSMISSION-ID' => 'T-1',
            'PAYPAL-TRANSMISSION-SIG' => 'sig',
            'PAYPAL-TRANSMISSION-TIME' => '2026-10-03T10:00:00Z',
        ]);
    }
}
