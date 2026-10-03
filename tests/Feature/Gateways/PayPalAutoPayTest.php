<?php

namespace Tests\Feature\Gateways;

use App\Automation\DailyAutomation;
use App\Billing\AutoPay;
use App\Billing\InvoiceManager;
use App\Billing\SavedMethods;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Automatic payments with a saved PayPal account when PayPal's answer is not a plain yes or no.
 */
class PayPalAutoPayTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 00:15:00');
        Mail::fake();
        Sleep::fake();
        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'id', 'client_secret' => 'secret']);
    }

    public function test_a_payment_paypal_is_still_processing_is_checked_and_not_charged_again(): void
    {
        $status = 'PENDING';
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response($this->order('PENDING'), 201),
            self::API.'/v2/payments/captures/CAP1' => function () use (&$status) {
                return Http::response($this->capture($status));
            },
        ]);
        $invoice = $this->renewalInvoice($this->clientWithPayPal());

        $this->assertSame(0, app(DailyAutomation::class)->run()['charge_failed']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame('CAP1', $invoice->autopay_pending['reference']);
        $this->assertSame(0, $invoice->autopay_attempts);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));

        // Still processing the next night: PayPal is asked, and nothing new is charged.
        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame('CAP1', $invoice->fresh()->autopay_pending['reference']);

        $status = 'COMPLETED';
        Carbon::setTestNow('2026-10-14 00:15:00');
        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(['CAP1'], $invoice->transactions()->pluck('reference')->all());
        $this->assertNull($invoice->autopay_pending);
        $this->assertCount(1, $this->orderRequests());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === self::API.'/v2/payments/captures/CAP1');
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));
    }

    public function test_a_processing_payment_paypal_declines_later_follows_the_retry_schedule(): void
    {
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response($this->order('PENDING'), 201),
            self::API.'/v2/payments/captures/CAP1' => Http::response($this->capture('DECLINED')),
        ]);
        $client = $this->clientWithPayPal();
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();
        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(1, $invoice->autopay_attempts);
        $this->assertSame('2026-10-16', $invoice->autopay_retry_at->toDateString());
        $this->assertNull($invoice->autopay_pending);
        $this->assertCount(1, $this->orderRequests());
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($client->email) && str_contains($mail->subjectLine, 'We could not charge'));
    }

    public function test_no_answer_from_paypal_is_never_followed_by_a_payment_with_a_new_key(): void
    {
        $answers = [
            Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
            Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 500),
            Http::response($this->order('COMPLETED'), 201),
        ];
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => function () use (&$answers) {
                return array_shift($answers);
            },
        ]);
        $invoice = $this->renewalInvoice($this->clientWithPayPal());

        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $invoice->autopay_attempts);
        $this->assertNotNull($invoice->autopay_pending);
        $this->assertSame('PayPal did not answer. The payment is checked before the next try.', $invoice->autopay_error);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));

        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(['CAP1'], $invoice->transactions()->pluck('reference')->all());

        // Every try was the same attempt: PayPal gives back the first payment instead of a second one.
        $requests = $this->orderRequests();
        $this->assertCount(3, $requests);
        $this->assertSame([$this->attemptId('invoice-1-1299-try-0')], array_values(array_unique(array_map(fn (Request $request): string => $request->header('PayPal-Request-Id')[0], $requests))));
        $this->assertSame([$this->attemptId('invoice-1-1299-try-0')], array_values(array_unique(array_map(fn (Request $request): string => $request['purchase_units'][0]['invoice_id'], $requests))));
    }

    public function test_staff_charging_after_paypal_did_not_answer_sends_that_same_try_again(): void
    {
        $sent = [];
        $answers = [Http::failedConnection('Operation timed out'), fn (): mixed => Http::response($this->order('COMPLETED'), 201)];
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => function (Request $request) use (&$answers, &$sent) {
                $sent[] = [$request->header('PayPal-Request-Id')[0], $request['purchase_units'][0]['invoice_id']];

                return array_shift($answers)($request);
            },
        ]);
        $invoice = $this->renewalInvoice($this->clientWithPayPal());

        // The nightly charge gets no answer at all, so PayPal may have taken the money.
        app(DailyAutomation::class)->run();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertArrayNotHasKey('reference', $invoice->fresh()->autopay_pending);

        // An hour later staff press "Charge now". It is the same try again, so PayPal can give back
        // the first payment, or refuse the repeated invoice ID, instead of taking the money twice.
        Carbon::setTestNow('2026-10-12 01:15:00');
        $result = app(AutoPay::class)->charge($invoice, by: 'Mer Las');

        $this->assertTrue($result->isPaid());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertCount(2, $sent);
        $this->assertSame([[$this->attemptId('invoice-1-1299-try-0'), $this->attemptId('invoice-1-1299-try-0')]], array_values(array_unique($sent, SORT_REGULAR)));
    }

    public function test_a_finished_order_without_its_capture_is_checked_and_not_failed(): void
    {
        $order = ['id' => 'ORDER1', 'status' => 'COMPLETED'];
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response($order, 201),
            self::API.'/v2/checkout/orders/ORDER1' => function () use (&$order) {
                return Http::response($order);
            },
        ]);
        $invoice = $this->renewalInvoice($this->clientWithPayPal());

        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $invoice->autopay_attempts);
        $this->assertSame('invoice-1-1299-try-0', $invoice->autopay_pending['key']);
        $this->assertSame('order:ORDER1', $invoice->autopay_pending['reference']);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));

        // The next night the order is looked up, and it shows the capture that took the money.
        $order = $this->order('COMPLETED');
        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(['CAP1'], $invoice->transactions()->pluck('reference')->all());
        $this->assertNull($invoice->autopay_pending);
        $this->assertCount(1, $this->orderRequests());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === self::API.'/v2/checkout/orders/ORDER1');
    }

    public function test_two_sites_on_one_paypal_account_never_send_the_same_attempt(): void
    {
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response($this->order('COMPLETED'), 201),
        ]);
        $client = $this->clientWithPayPal();
        $invoice = $this->renewalInvoice($client);
        $method = $client->paymentMethods()->firstOrFail();
        $gateway = app(ExtensionManager::class)->gateway('paypal');

        // Both sites number their invoices from 1, so both have an "invoice-1-1299-try-0".
        $gateway->chargeSaved($method, $invoice, 'invoice-1-1299-try-0');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        $gateway->chargeSaved($method, $invoice, 'invoice-1-1299-try-0');
        $gateway->chargeSaved($method, $invoice, 'invoice-1-'.str_repeat('9', 120).'-try-0');

        [$siteA, $siteB, $long] = $this->orderRequests();
        $this->assertNotSame($siteA->header('PayPal-Request-Id')[0], $siteB->header('PayPal-Request-Id')[0]);
        $this->assertNotSame($siteA['purchase_units'][0]['invoice_id'], $siteB['purchase_units'][0]['invoice_id']);
        $this->assertStringEndsWith('-invoice-1-1299-try-0', $siteB['purchase_units'][0]['invoice_id']);
        $this->assertLessThanOrEqual(108, strlen($long->header('PayPal-Request-Id')[0]));
        $this->assertSame($long->header('PayPal-Request-Id')[0], $long['purchase_units'][0]['invoice_id']);
    }

    public function test_paypal_refusing_a_repeated_attempt_is_not_a_failed_payment(): void
    {
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'DUPLICATE_INVOICE_ID', 'description' => 'Duplicate Invoice ID detected.']]], 422),
        ]);
        $invoice = $this->renewalInvoice($this->clientWithPayPal());

        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $invoice->autopay_attempts);
        $this->assertNotNull($invoice->autopay_pending);
        $this->assertSame('PayPal says this payment was sent before. Check it in PayPal and record it here, or ask the client to pay the invoice.', $invoice->autopay_error);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));
    }

    public function test_an_unclear_try_is_sent_again_to_paypal_when_the_client_makes_a_card_the_default(): void
    {
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        $answers = [
            Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
            Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
            Http::response($this->order('COMPLETED'), 201),
        ];
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => function () use (&$answers) {
                return array_shift($answers);
            },
            'api.stripe.com/*' => Http::response(['id' => 'pi_card', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd']),
        ]);
        $client = $this->clientWithPayPal();
        $invoice = $this->renewalInvoice($client);

        // PayPal does not answer, so it may have taken the money.
        app(DailyAutomation::class)->run();
        $this->assertArrayNotHasKey('reference', $invoice->fresh()->autopay_pending);

        // Before the next night Raz makes a card the default and adds funds to the wallet.
        app(SavedMethods::class)->makeDefault($this->card($client));
        $client->forceFill(['credit' => 500])->save();

        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        // The same PayPal try is sent again, and PayPal gives back the one payment it made.
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(['CAP1'], $invoice->transactions()->pluck('reference')->all());
        $this->assertCount(3, $this->orderRequests());
        $this->assertSame([$this->attemptId('invoice-1-1299-try-0')], array_values(array_unique(array_map(fn (Request $request): string => $request->header('PayPal-Request-Id')[0], $this->orderRequests()))));
        $this->assertSame(500, $client->fresh()->credit);
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.stripe.com/'));
    }

    public function test_staff_charging_after_paypal_refused_a_repeated_attempt_does_not_charge_the_new_default_card(): void
    {
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'DUPLICATE_INVOICE_ID', 'description' => 'Duplicate Invoice ID detected.']]], 422),
            'api.stripe.com/*' => Http::response(['id' => 'pi_card', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd']),
        ]);
        $client = $this->clientWithPayPal();
        $invoice = $this->renewalInvoice($client);

        // PayPal says this try was sent before: it may already have the money.
        app(DailyAutomation::class)->run();
        $card = $this->card($client);
        app(SavedMethods::class)->makeDefault($card);

        Carbon::setTestNow('2026-10-12 01:15:00');
        $this->assertTrue(app(AutoPay::class)->charge($invoice, $card, 'Mer Las')->isPending());
        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(['paypal', 'invoice-1-1299-try-0'], [$invoice->autopay_pending['gateway'], $invoice->autopay_pending['key']]);
        $this->assertCount(3, $this->orderRequests());
        $this->assertSame([$this->attemptId('invoice-1-1299-try-0')], array_values(array_unique(array_map(fn (Request $request): string => $request['purchase_units'][0]['invoice_id'], $this->orderRequests()))));
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.stripe.com/'));
    }

    public function test_an_unclear_try_whose_paypal_account_was_removed_is_left_for_staff_to_check(): void
    {
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
            self::API.'/v3/vault/payment-tokens/*' => Http::response(null, 204),
            'api.stripe.com/*' => Http::response(['id' => 'pi_card', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd']),
        ]);
        $client = $this->clientWithPayPal();
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();
        $pending = $invoice->fresh()->autopay_pending;

        // Raz saves a card and removes the PayPal account, so the card pays renewals from now on.
        $card = $this->card($client);
        app(SavedMethods::class)->forget($client->paymentMethods()->where('gateway', 'paypal')->sole(), 'Raz');
        $this->assertTrue($card->fresh()->is_default);

        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(0, $invoice->autopay_attempts);
        $this->assertSame($pending, $invoice->autopay_pending);
        $this->assertSame('The last payment try is not clear, and its payment method can no longer be used. Check the payment at the payment service and record it here, or ask the client to pay the invoice.', $invoice->autopay_error);
        $this->assertCount(2, $this->orderRequests());
        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.stripe.com/'));
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));
    }

    public function test_a_try_sent_before_the_site_marker_is_still_found_and_not_charged_again(): void
    {
        Carbon::setTestNow('2026-12-01 00:15:00');
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response($this->order('COMPLETED'), 201),
            // A capture made before 0.6.12 names only the invoice.
            self::API.'/v2/payments/captures/CAP1' => Http::response($this->capture('COMPLETED', customId: '1')),
        ]);
        $client = $this->clientWithPayPal();
        $invoice = $this->renewalInvoice($client);
        $invoice->forceFill(['autopay_pending' => [
            'gateway' => 'paypal', 'method' => $client->paymentMethods()->sole()->id, 'customer' => 'CUST1',
            'key' => 'invoice-1-1299-try-0', 'since' => '2026-11-30T00:15:00+00:00', 'reference' => 'CAP1',
        ]])->save();

        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(['CAP1'], $invoice->transactions()->pluck('reference')->all());
        $this->assertCount(0, $this->orderRequests());
    }

    /**
     * The ID this site sends PayPal for one try of an automatic payment.
     */
    private function attemptId(string $attemptKey): string
    {
        return 'nuvabill-'.substr(hash_hmac('sha256', 'nuvabill-paypal-attempt', (string) config('app.key')), 0, 12).'-'.$attemptKey;
    }

    /**
     * The custom_id this site puts on its PayPal orders for invoice 1, which PayPal sends back.
     */
    private function customId(): string
    {
        return '1:'.substr(hash_hmac('sha256', 'nuvabill-paypal-site', (string) config('app.key')), 0, 24);
    }

    private function card(Client $client): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'client_id' => $client->id, 'gateway' => 'stripe', 'type' => PaymentMethod::TYPE_CARD, 'reference' => 'pm_visa',
            'customer_reference' => 'cus_raz', 'brand' => 'visa', 'last4' => '4242', 'expires_month' => 8, 'expires_year' => 2028, 'is_default' => false,
        ]);
    }

    /**
     * @return list<Request>
     */
    private function orderRequests(): array
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::API.'/v2/checkout/orders')
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function order(string $captureStatus): array
    {
        return [
            'id' => 'ORDER1',
            'status' => 'COMPLETED',
            'purchase_units' => [['custom_id' => $this->customId(), 'payments' => ['captures' => [
                ['id' => 'CAP1', 'status' => $captureStatus, 'amount' => ['currency_code' => 'USD', 'value' => '12.99']],
            ]]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capture(string $status, ?string $customId = null): array
    {
        return ['id' => 'CAP1', 'status' => $status, 'custom_id' => $customId ?? $this->customId(), 'amount' => ['currency_code' => 'USD', 'value' => '12.99']];
    }

    private function clientWithPayPal(): Client
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'email' => 'raz@example.test', 'currency' => 'USD']);

        PaymentMethod::query()->create([
            'client_id' => $client->id, 'gateway' => 'paypal', 'type' => PaymentMethod::TYPE_PAYPAL, 'reference' => 'VAULT1',
            'customer_reference' => 'CUST1', 'email' => 'raz@example.test', 'is_default' => true,
        ]);

        return $client;
    }

    private function renewalInvoice(Client $client): Invoice
    {
        $invoice = app(InvoiceManager::class)->create($client, [[
            'description' => 'Business Hosting',
            'amount' => 1299,
            'billing_key' => 'service-'.$client->id.'-'.today()->toDateString(),
        ]], dueAt: today(), currency: 'USD');
        $this->assertSame(1, $invoice->id);

        return $invoice;
    }
}
