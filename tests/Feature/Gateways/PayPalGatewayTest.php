<?php

namespace Tests\Feature\Gateways;

use App\Billing\ExchangeRates;
use App\Billing\SavedMethods;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
                    'custom_id' => (string) $invoice->id,
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
            && $request['purchase_units'][0]['custom_id'] === (string) $invoice->id);

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
}
