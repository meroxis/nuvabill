<?php

namespace Tests\Feature\Gateways;

use App\Automation\DailyAutomation;
use App\Billing\InvoiceManager;
use App\Enums\InvoiceStatus;
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
        $this->assertSame(['nuvabill-invoice-1-1299-try-0'], array_values(array_unique(array_map(fn (Request $request): string => $request->header('PayPal-Request-Id')[0], $requests))));
        $this->assertSame(['nuvabill-invoice-1-1299-try-0'], array_values(array_unique(array_map(fn (Request $request): string => $request['purchase_units'][0]['invoice_id'], $requests))));
    }

    public function test_a_finished_order_without_its_capture_is_checked_and_not_failed(): void
    {
        Http::fake([
            self::API.'/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API.'/v2/checkout/orders' => Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED'], 201),
        ]);
        $invoice = $this->renewalInvoice($this->clientWithPayPal());

        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $invoice->autopay_attempts);
        $this->assertSame('invoice-1-1299-try-0', $invoice->autopay_pending['key']);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));
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
        $this->assertSame('PayPal says this payment was sent before. Check it in PayPal before you charge the invoice again.', $invoice->autopay_error);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));
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
            'purchase_units' => [['custom_id' => '1', 'payments' => ['captures' => [
                ['id' => 'CAP1', 'status' => $captureStatus, 'amount' => ['currency_code' => 'USD', 'value' => '12.99']],
            ]]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capture(string $status): array
    {
        return ['id' => 'CAP1', 'status' => $status, 'custom_id' => '1', 'amount' => ['currency_code' => 'USD', 'value' => '12.99']];
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
