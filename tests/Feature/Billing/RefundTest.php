<?php

namespace Tests\Feature\Billing;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'client-id', 'client_secret' => 'client-secret']);
        $this->signInAdmin();
    }

    public function test_staff_refund_a_stripe_payment_through_stripe(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'amount' => 1399, 'status' => 'succeeded'])]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Send the money back through the payment gateway');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status', 'Invoice refunded.');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/refunds'
            && $request['payment_intent'] === 'pi_test_1'
            && (int) $request['amount'] === 1399);

        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'type' => 'refund', 'gateway' => 'stripe', 'reference' => 're_1', 'amount' => -1399]);
    }

    public function test_staff_refund_a_paypal_capture_through_paypal(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v2/payments/captures/*' => Http::response(['id' => 'REFUND1', 'status' => 'COMPLETED'], 201),
        ]);
        $invoice = $this->paidInvoice(899, 'paypal', 'CAPTURE1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE1/refund'
            && $request['amount'] === ['currency_code' => 'USD', 'value' => '8.99']);

        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'type' => 'refund', 'reference' => 'REFUND1', 'amount' => -899]);
    }

    public function test_a_bank_transfer_refund_is_only_recorded(): void
    {
        Http::fake();
        $invoice = $this->paidInvoice(1000, 'banktransfer', 'BANK-REF-1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status');

        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertDatabaseHas('transactions', ['invoice_id' => $invoice->id, 'type' => 'refund', 'reference' => null, 'amount' => -1000]);
    }

    public function test_staff_can_record_a_refund_without_calling_the_gateway(): void
    {
        Http::fake();
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '0']);

        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_a_refused_refund_keeps_the_invoice_paid_and_can_be_retried(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::sequence()
            ->push(['id' => 're_a', 'amount' => 500, 'status' => 'succeeded'])
            ->push(['error' => ['message' => 'Card network error']], 402)
            ->push(['id' => 're_b', 'amount' => 899, 'status' => 'succeeded'])]);

        $invoice = Invoice::factory()->create(['total' => 1399]);
        app(PaymentRecorder::class)->record($invoice, 500, 'stripe', 'pi_a');
        app(PaymentRecorder::class)->record($invoice, 899, 'stripe', 'pi_b');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('error', 'The refund did not finish: Stripe could not refund the payment: Card network error');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->transactions()->where('type', 'refund')->count());

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])
            ->assertSessionHas('status');

        Http::assertSentCount(3);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertSame(-1399, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
    }

    public function test_an_overpayment_is_refunded_only_up_to_the_invoice_total(): void
    {
        Http::fake();
        $invoice = Invoice::factory()->create(['total' => 1000]);
        app(PaymentRecorder::class)->record($invoice, 1200, 'banktransfer');

        $this->post(route('admin.invoices.refund', $invoice));

        $this->assertSame(-1000, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
    }

    public function test_an_unpaid_invoice_cannot_be_refunded(): void
    {
        $invoice = Invoice::factory()->create();

        $this->post(route('admin.invoices.refund', $invoice))
            ->assertSessionHas('error', 'Only paid invoices can be refunded.');

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    private function paidInvoice(int $total, string $gateway, string $reference): Invoice
    {
        $invoice = Invoice::factory()->create(['total' => $total]);
        app(PaymentRecorder::class)->record($invoice, $total, $gateway, $reference);

        return $invoice->fresh();
    }
}
