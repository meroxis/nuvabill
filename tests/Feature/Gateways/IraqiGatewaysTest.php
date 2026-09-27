<?php

namespace Tests\Feature\Gateways;

use App\Billing\ExchangeRates;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IraqiGatewaysTest extends TestCase
{
    use RefreshDatabase;

    private const FIB = 'https://fib.stage.fib.iq';

    public function test_fib_shows_a_qr_code_and_only_trusts_the_status_read_from_fib(): void
    {
        $this->enableGateway('fib', ['client_id' => 'shop', 'client_secret' => 'secret', 'mode' => 'stage']);
        $status = 'UNPAID';

        Http::fake([
            self::FIB.'/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 60]),
            self::FIB.'/protected/v1/payments' => Http::response([
                'paymentId' => 'pay-123', 'readableCode' => 'S3LE-NZ2S-ZNGF', 'qrCode' => 'data:image/png;base64,iVBORw0KGgo=',
                'validUntil' => now()->addMinutes(10)->toIso8601String(), 'personalAppLink' => 'https://personal.fib.iq/pay/pay-123',
            ], 202),
            self::FIB.'/protected/v1/payments/pay-123/status' => function () use (&$status) {
                return Http::response(['paymentId' => 'pay-123', 'status' => $status, 'amount' => ['amount' => 25000, 'currency' => 'IQD']]);
            },
        ]);

        [$client, $invoice] = $this->invoice(2500000);

        $this->actingAs($client, 'web')->post(route('client.invoices.pay', $invoice), ['gateway' => 'fib'])
            ->assertRedirect(route('client.invoices.show', $invoice));
        $this->get(route('client.invoices.show', $invoice))->assertSee('S3LE-NZ2S-ZNGF')->assertSee('data:image/png;base64,iVBORw0KGgo=', false);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::FIB.'/protected/v1/payments'
            && $request['monetaryValue'] === ['amount' => 25000, 'currency' => 'IQD']
            && $request->hasHeader('Authorization', 'Bearer token-1'));

        $this->postJson(route('webhooks.gateway', 'fib'), ['id' => 'pay-123', 'status' => 'PAID'])->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status, 'A callback saying PAID is not believed until FIB says so.');

        $this->postJson(route('webhooks.gateway', 'fib'), ['id' => 'someone-elses-payment', 'status' => 'PAID'])->assertOk();

        $status = 'PAID';
        $this->getJson(route('client.invoices.payment-status', $invoice))->assertExactJson(['paid' => true]);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame('pay-123', $invoice->transactions()->sole()->reference);
        $this->assertSame(PaymentIntent::STATUS_PAID, PaymentIntent::query()->sole()->status);
    }

    public function test_fastpay_redirects_and_confirms_the_ipn_with_the_validate_call(): void
    {
        $this->enableGateway('fastpay', ['store_id' => 'store_1', 'store_password' => 'pass', 'mode' => 'staging']);
        $validated = ['code' => 404, 'messages' => ['Order not found'], 'data' => null];

        Http::fake([
            'staging-apigw-merchant.fast-pay.iq/api/v1/public/pgw/payment/initiation' => Http::response(['code' => 200, 'messages' => [], 'data' => ['redirect_uri' => 'https://staging-pgw.fast-pay.iq/pay?token=abc']]),
            'staging-apigw-merchant.fast-pay.iq/api/v1/public/pgw/payment/validate' => function () use (&$validated) {
                return Http::response($validated);
            },
        ]);

        [$client, $invoice] = $this->invoice(1500000);

        $this->actingAs($client, 'web')->post(route('client.invoices.pay', $invoice), ['gateway' => 'fastpay'])
            ->assertRedirect('https://staging-pgw.fast-pay.iq/pay?token=abc');

        $orderId = PaymentIntent::query()->sole()->reference;
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/payment/initiation') && $request['bill_amount'] === 15000 && $request['order_id'] === $orderId && $request['store_id'] === 'store_1');

        $this->postJson(route('webhooks.gateway', 'fastpay'), ['merchant_order_id' => $orderId, 'status' => 'Success', 'received_amount' => '15000.00'])->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        $validated = ['code' => 200, 'data' => ['gw_transaction_id' => 'CUL1NUB713', 'merchant_order_id' => $orderId, 'received_amount' => '15000.00', 'currency' => 'IQD', 'status' => 'Success']];
        $this->postJson(route('webhooks.gateway', 'fastpay'), ['merchant_order_id' => $orderId, 'status' => 'Success'])->assertOk();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(1500000, $invoice->transactions()->sole()->amount);
        $this->assertSame('CUL1NUB713', $invoice->transactions()->sole()->reference);
    }

    public function test_wayl_checks_the_webhook_signature_and_the_link_status(): void
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);

        Http::fake([
            'api.thewayl.com/api/v1/links' => Http::response(['data' => ['id' => 'cmlink_1', 'code' => 'I94F590I', 'url' => 'https://checkout.thewayl.com/pay/I94F590I', 'status' => 'Created']], 201),
            'api.thewayl.com/api/v1/links/*' => Http::response(['data' => ['id' => 'cmlink_1', 'total' => '30000', 'status' => 'Complete']]),
        ]);

        [$client, $invoice] = $this->invoice(3000000);

        $this->actingAs($client, 'web')->post(route('client.invoices.pay', $invoice), ['gateway' => 'wayl'])
            ->assertRedirect('https://checkout.thewayl.com/pay/I94F590I');

        $reference = PaymentIntent::query()->sole()->reference;
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.thewayl.com/api/v1/links'
            && $request['env'] === 'test' && $request['total'] === 30000 && $request->hasHeader('X-WAYL-AUTHENTICATION', 'wayl-token'));

        $payload = json_encode(['event' => 'order.completed', 'referenceId' => $reference, 'paymentStatus' => 'Complete']);

        $this->call('POST', route('webhooks.gateway', 'wayl'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WAYL_SIGNATURE_256' => 'forged'], $payload)->assertStatus(400);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        $secret = hash_hmac('sha256', 'nuvabill-wayl-webhook', (string) config('app.key'));
        $this->call('POST', route('webhooks.gateway', 'wayl'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WAYL_SIGNATURE_256' => hash_hmac('sha256', $payload, $secret)], $payload)->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(3000000, $invoice->fresh()->amount_paid);
    }

    public function test_iraqi_gateways_are_only_offered_for_dinar_invoices(): void
    {
        $this->enableGateway('fib', ['client_id' => 'shop', 'client_secret' => 'secret']);
        $this->enableGateway('banktransfer', ['instructions' => 'Pay to our bank.']);
        $client = Client::factory()->create(['currency' => 'USD']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'USD', 'total' => 1000]);

        $this->actingAs($client, 'web')->get(route('client.invoices.show', $invoice))->assertOk()->assertDontSee('FIB (First Iraqi Bank)');
        $this->post(route('client.invoices.pay', $invoice), ['gateway' => 'fib'])->assertSessionHas('error');
    }

    public function test_with_an_exchange_rate_wayl_charges_a_dollar_invoice_in_dinar(): void
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);
        app(ExchangeRates::class)->save(['IQD' => 1310]);
        $paidTotal = '77290';

        Http::fake([
            'api.thewayl.com/api/v1/links' => Http::response(['data' => ['id' => 'cmlink_2', 'code' => 'USD59', 'url' => 'https://checkout.thewayl.com/pay/USD59']], 201),
            'api.thewayl.com/api/v1/links/*' => function () use (&$paidTotal) {
                return Http::response(['data' => ['id' => 'cmlink_2', 'total' => $paidTotal, 'status' => 'Complete']]);
            },
        ]);

        $client = Client::factory()->create(['currency' => 'USD']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'USD', 'total' => 5900]);

        $this->actingAs($client, 'web')->get(route('client.invoices.show', $invoice))->assertOk()->assertSee('Wayl')->assertSee('77,290');

        $this->post(route('client.invoices.pay', $invoice), ['gateway' => 'wayl'])->assertRedirect('https://checkout.thewayl.com/pay/USD59');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.thewayl.com/api/v1/links' && $request['total'] === 77290 && $request['currency'] === 'IQD');

        $paidTotal = '1000';
        $this->get(route('client.invoices.return', [$invoice, 'wayl']));
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status, 'Less dinar than was charged does not pay the invoice.');

        $paidTotal = '77290';
        $this->get(route('client.invoices.return', [$invoice, 'wayl']));

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(5900, $invoice->amount_paid);

        $payment = $invoice->transactions()->sole();
        $this->assertSame('USD', $payment->currency);
        $this->assertSame(7729000, $payment->meta['paid_amount']);
        $this->assertSame('IQD', $payment->meta['paid_currency']);
    }

    /**
     * @return array{0: Client, 1: Invoice}
     */
    private function invoice(int $total): array
    {
        $client = Client::factory()->create(['currency' => 'IQD']);

        return [$client, Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'IQD', 'total' => $total])];
    }
}
