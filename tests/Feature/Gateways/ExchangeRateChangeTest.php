<?php

namespace Tests\Feature\Gateways;

use App\Billing\ExchangeRates;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A payment in another currency is priced at the exchange rate of the moment it is started. When
 * the rate has changed by the time the money arrives, the payment counts for what it is worth at
 * the new rate, and the rest of the invoice stays to pay.
 */
class ExchangeRateChangeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Invoice $invoice;

    /**
     * The dinar Wayl reports for the link made last.
     */
    private ?string $waylPaid = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(ExchangeRates::class)->save(['IQD' => 1310]);
        $this->client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => 'Las', 'currency' => 'USD']);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'currency' => 'USD', 'total' => 5900]);
    }

    public function test_a_wayl_link_paid_after_the_rate_went_up_pays_what_it_is_worth_today(): void
    {
        $this->startWaylPayment();
        $this->assertSame(7729000, PaymentIntent::query()->sole()->meta['charged_amount']);

        app(ExchangeRates::class)->save(['IQD' => 1500]);

        $this->get(route('client.invoices.return', [$this->invoice, 'wayl']))
            ->assertRedirect(route('client.invoices.show', $this->invoice))
            ->assertSessionHas('status', 'Thank you! Your payment was received. The exchange rate changed after you started it, so $7.47 is still to pay.');

        $invoice = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(5153, $invoice->amount_paid, '77,290 dinar at 1,500 to the dollar.');

        $payment = $invoice->transactions()->sole();
        $this->assertSame(7729000, $payment->meta['paid_amount']);
        $this->assertSame('IQD', $payment->meta['paid_currency']);
        $this->assertEquals(1500, $payment->meta['rate']);
        $this->assertEquals(1310, $payment->meta['quoted_rate']);
        $this->assertSame(5900, $payment->meta['quoted_amount']);

        // Staff are told once, also when Wayl reports the same payment again.
        $this->get(route('client.invoices.return', [$this->invoice, 'wayl']));
        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(
            'The exchange rate changed after a payment for invoice '.$invoice->displayNumber().' was started. The '.money(7729000, 'IQD').' that arrived counts as $51.53 at today\'s rate, not $59.00.',
            ActivityLog::query()->where('action', 'payment.rate_changed')->sole()->description,
        );

        $this->get(route('client.invoices.show', $this->invoice))
            ->assertOk()
            ->assertSee('The exchange rate changed after a payment for this invoice was started, so that payment did not cover the full amount. The rest is still to pay.')
            ->assertSee('$7.47');

        $this->signInAdmin();
        $this->get(route('admin.invoices.show', $this->invoice))->assertOk()->assertSee('Exchange rate changed');
    }

    public function test_the_rest_is_paid_with_a_new_link_at_the_new_rate(): void
    {
        $this->startWaylPayment();
        app(ExchangeRates::class)->save(['IQD' => 1500]);
        $this->get(route('client.invoices.return', [$this->invoice, 'wayl']));

        // $7.47 at 1,500 is 11,205 dinar.
        $this->startWaylPayment('11205');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.thewayl.com/api/v1/links' && $request['total'] === 11205);
        $this->get(route('client.invoices.return', [$this->invoice, 'wayl']))
            ->assertSessionHas('status', 'Thank you! Your payment was received.');

        $invoice = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(5900, $invoice->amount_paid);
        $this->assertSame(0, $this->client->fresh()->credit);
    }

    public function test_a_small_rate_change_still_pays_the_invoice_in_full(): void
    {
        $this->startWaylPayment();
        app(ExchangeRates::class)->save(['IQD' => 1320]);

        $this->get(route('client.invoices.return', [$this->invoice, 'wayl']))
            ->assertSessionHas('status', 'Thank you! Your payment was received.');

        $invoice = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(5900, $invoice->amount_paid);
        $this->assertArrayNotHasKey('quoted_amount', $invoice->transactions()->sole()->meta);
        $this->assertSame(0, ActivityLog::query()->where('action', 'payment.rate_changed')->count());
    }

    public function test_a_rate_that_went_down_pays_the_invoice_and_nothing_more(): void
    {
        $this->startWaylPayment();
        app(ExchangeRates::class)->save(['IQD' => 1200]);

        $this->get(route('client.invoices.return', [$this->invoice, 'wayl']));

        $invoice = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(5900, $invoice->amount_paid);
        $this->assertSame(0, $this->client->fresh()->credit);
    }

    public function test_without_a_rate_today_the_price_of_the_day_it_was_started_stands(): void
    {
        $this->startWaylPayment();
        app(ExchangeRates::class)->save([]);

        // Wayl is no longer offered for dollar invoices, so the payment arrives by webhook.
        $reference = PaymentIntent::query()->sole()->reference;
        $payload = json_encode(['event' => 'order.completed', 'referenceId' => $reference, 'paymentStatus' => 'Complete']);
        $secret = hash_hmac('sha256', 'nuvabill-wayl-webhook', (string) config('app.key'));
        $this->call('POST', route('webhooks.gateway', 'wayl'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WAYL_SIGNATURE_256' => hash_hmac('sha256', $payload, $secret)], $payload)->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $this->invoice->fresh()->status);
        $this->assertSame(5900, $this->invoice->fresh()->amount_paid);
    }

    public function test_a_fastpay_payment_reported_by_its_notice_is_checked_the_same_way(): void
    {
        $this->enableGateway('fastpay', ['store_id' => 'store_1', 'store_password' => 'pass', 'mode' => 'staging']);
        $orderId = null;

        Http::fake([
            'staging-apigw-merchant.fast-pay.iq/api/v1/public/pgw/payment/initiation' => Http::response(['code' => 200, 'messages' => [], 'data' => ['redirect_uri' => 'https://staging-pgw.fast-pay.iq/pay?token=abc']]),
            'staging-apigw-merchant.fast-pay.iq/api/v1/public/pgw/payment/validate' => function () use (&$orderId) {
                return Http::response(['code' => 200, 'data' => ['gw_transaction_id' => 'CUL1NUB713', 'merchant_order_id' => $orderId, 'received_amount' => '77290.00', 'currency' => 'IQD', 'status' => 'Success']]);
            },
        ]);

        $this->actingAs($this->client, 'web')->post(route('client.invoices.pay', $this->invoice), ['gateway' => 'fastpay'])
            ->assertRedirect('https://staging-pgw.fast-pay.iq/pay?token=abc');
        $orderId = PaymentIntent::query()->sole()->reference;

        app(ExchangeRates::class)->save(['IQD' => 1500]);
        $this->postJson(route('webhooks.gateway', 'fastpay'), ['merchant_order_id' => $orderId, 'status' => 'Success'])->assertOk();

        $invoice = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(5153, $invoice->amount_paid);
        $this->assertSame(747, $invoice->balance());
        $this->assertSame(1, ActivityLog::query()->where('action', 'payment.rate_changed')->count());
    }

    public function test_a_waiting_fib_payment_is_shown_again_only_while_its_price_is_the_price_today(): void
    {
        $this->enableGateway('fib', ['client_id' => 'shop', 'client_secret' => 'secret', 'mode' => 'stage']);
        $made = 0;

        Http::fake([
            'fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 60]),
            'fib.stage.fib.iq/protected/v1/payments' => function () use (&$made) {
                $made++;

                return Http::response(['paymentId' => 'pay-'.$made, 'readableCode' => 'CODE-'.$made, 'qrCode' => 'data:image/png;base64,iVBORw0KGgo=', 'validUntil' => now()->addMinutes(10)->toIso8601String()], 202);
            },
        ]);

        $this->actingAs($this->client, 'web');
        $this->post(route('client.invoices.pay', $this->invoice), ['gateway' => 'fib'])->assertSessionHas('payment_qr');
        $this->post(route('client.invoices.pay', $this->invoice), ['gateway' => 'fib'])->assertSessionHas('payment_qr');
        $this->assertSame(1, $made, 'The same payment is shown again while the rate is the same.');

        app(ExchangeRates::class)->save(['IQD' => 1500]);
        $this->post(route('client.invoices.pay', $this->invoice), ['gateway' => 'fib'])
            ->assertSessionHas('payment_qr', fn (array $qr): bool => $qr['code'] === 'CODE-2');

        $this->assertSame(2, $made);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fib.stage.fib.iq/protected/v1/payments'
            && $request['monetaryValue'] === ['amount' => 88500, 'currency' => 'IQD']);
        $this->assertSame(8850000, PaymentIntent::query()->latest('id')->first()->meta['charged_amount']);
    }

    public function test_a_page_waiting_for_fib_learns_that_a_payment_arrived_and_a_part_is_still_to_pay(): void
    {
        $this->enableGateway('fib', ['client_id' => 'shop', 'client_secret' => 'secret', 'mode' => 'stage']);

        Http::fake([
            'fib.stage.fib.iq/auth/realms/fib-online-shop/protocol/openid-connect/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 60]),
            'fib.stage.fib.iq/protected/v1/payments' => Http::response(['paymentId' => 'pay-1', 'readableCode' => 'CODE-1', 'qrCode' => 'data:image/png;base64,iVBORw0KGgo=', 'validUntil' => now()->addMinutes(10)->toIso8601String()], 202),
            'fib.stage.fib.iq/protected/v1/payments/pay-1/status' => Http::response(['paymentId' => 'pay-1', 'status' => 'PAID', 'amount' => ['amount' => 77290, 'currency' => 'IQD']]),
        ]);

        $this->actingAs($this->client, 'web')->post(route('client.invoices.pay', $this->invoice), ['gateway' => 'fib'])->assertSessionHas('payment_qr');
        app(ExchangeRates::class)->save(['IQD' => 1500]);

        $this->getJson(route('client.invoices.payment-status', $this->invoice))->assertExactJson(['paid' => false, 'received' => true]);

        $this->assertSame(5153, $this->invoice->fresh()->amount_paid);
        $this->assertSame(PaymentIntent::STATUS_PAID, PaymentIntent::query()->sole()->status);

        // Asked again, there is nothing new.
        $this->travel(10)->seconds();
        $this->getJson(route('client.invoices.payment-status', $this->invoice))->assertExactJson(['paid' => false]);
        $this->assertSame(1, $this->invoice->transactions()->count());
    }

    /**
     * The client presses Pay with Wayl, and Wayl reports the link as paid with this many dinar.
     */
    private function startWaylPayment(string $paidDinar = '77290'): void
    {
        if ($this->waylPaid === null) {
            $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);

            Http::fake([
                'api.thewayl.com/api/v1/links' => fn () => Http::response(['data' => ['id' => 'cmlink_'.$this->waylPaid, 'code' => 'USD59', 'url' => 'https://checkout.thewayl.com/pay/USD59']], 201),
                'api.thewayl.com/api/v1/links/*' => fn () => Http::response(['data' => ['id' => 'cmlink_'.$this->waylPaid, 'total' => $this->waylPaid, 'status' => 'Complete']]),
            ]);
        }

        $this->waylPaid = $paidDinar;

        $this->actingAs($this->client, 'web')->post(route('client.invoices.pay', $this->invoice), ['gateway' => 'wayl'])
            ->assertRedirect('https://checkout.thewayl.com/pay/USD59');
    }
}
