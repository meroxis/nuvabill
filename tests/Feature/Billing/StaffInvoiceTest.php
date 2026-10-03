<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Staff work on invoices: payments typed in by hand, invoices made by hand, and refunds that can
 * only send back what came in.
 */
class StaffInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function merLas(): Client
    {
        return Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD']);
    }

    public function test_a_reference_recorded_before_is_not_reported_as_a_new_payment(): void
    {
        $this->signInAdmin();
        $client = $this->merLas();
        $first = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        $second = app(InvoiceManager::class)->create($client, [['description' => 'Domain', 'amount' => 1000]]);
        $payment = ['amount' => '10', 'method' => 'banktransfer', 'reference' => 'TRX-5531', 'paid_at' => today()->toDateString()];

        $this->post(route('admin.invoices.payments.store', $first), $payment)->assertSessionHas('status');
        $this->post(route('admin.invoices.payments.store', $second), $payment)
            ->assertSessionHas('error', 'Reference TRX-5531 was already recorded on invoice '.$first->displayNumber().'. Use a different reference.')
            ->assertSessionMissing('status');

        $this->assertSame(InvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $second->fresh()->status);
        $this->assertSame(0, $second->fresh()->amount_paid);
        $this->assertSame(1, Transaction::query()->count());

        // Wallet payments are made from the wallet, never typed in by hand.
        $this->post(route('admin.invoices.payments.store', $second), ['method' => 'credit', 'reference' => 'W-1'] + $payment)->assertSessionHasErrors('method');
        $this->post(route('admin.invoices.payments.store', $second), ['method' => 'anything', 'reference' => 'W-2'] + $payment)->assertSessionHasErrors('method');
        $this->assertSame(1, Transaction::query()->count());

        $this->post(route('admin.invoices.payments.store', $second), ['method' => 'manual', 'reference' => 'CASH-1'] + $payment)->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Paid, $second->fresh()->status);
    }

    public function test_an_invoice_total_cannot_be_below_zero_and_a_zero_one_is_settled(): void
    {
        Mail::fake();
        $this->signInAdmin();
        $client = $this->merLas();
        $due = today()->addDays(7)->toDateString();

        $this->post(route('admin.invoices.store'), ['client' => (string) $client->id, 'due_at' => $due, 'send_email' => '1', 'items' => [
            ['description' => 'Hosting', 'amount' => '10'],
            ['description' => 'Goodwill', 'amount' => '-15'],
        ]])->assertSessionHasErrors('items');
        $this->assertSame(0, Invoice::query()->count());

        $this->post(route('admin.invoices.store'), ['client' => (string) $client->id, 'due_at' => $due, 'send_email' => '1', 'items' => [
            ['description' => 'Hosting', 'amount' => '10'],
            ['description' => 'Goodwill', 'amount' => '-10'],
        ]])->assertRedirect();
        $this->assertSame(InvoiceStatus::Paid, Invoice::query()->sole()->status, 'Nothing to pay, so it is settled at once.');
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'is ready'));

        // A draft that comes to nothing is settled when it is published.
        $this->post(route('admin.invoices.store'), ['client' => (string) $client->id, 'due_at' => $due, 'draft' => '1', 'items' => [
            ['description' => 'Free setup', 'amount' => '0'],
        ]])->assertRedirect();
        $draft = Invoice::query()->where('status', InvoiceStatus::Draft)->sole();
        $this->post(route('admin.invoices.publish', $draft))->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Paid, $draft->fresh()->status);
    }

    public function test_the_api_refuses_an_invoice_below_zero_and_settles_a_zero_one(): void
    {
        Mail::fake();
        [, $plain] = ApiToken::issue(Admin::factory()->create(['name' => 'Raz']), 'Accounting', true);
        $headers = ['Authorization' => "Bearer {$plain}"];
        $client = $this->merLas();

        $this->postJson('/api/v1/invoices', ['client_id' => $client->id, 'items' => [
            ['description' => 'Hosting', 'amount' => 1000],
            ['description' => 'Goodwill', 'amount' => -1500],
        ]], $headers)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, Invoice::query()->count());

        $this->postJson('/api/v1/invoices', ['client_id' => $client->id, 'items' => [
            ['description' => 'Hosting', 'amount' => 1000],
            ['description' => 'Goodwill', 'amount' => -1000],
        ]], $headers)->assertCreated()->assertJsonPath('data.status', InvoiceStatus::Paid->value);
    }

    public function test_an_invoice_with_nothing_left_to_pay_gets_no_reminder(): void
    {
        Mail::fake();
        $this->setSettings(['automation.reminder_days' => [1]]);
        $nothing = Invoice::factory()->overdue(10)->create(['client_id' => $this->merLas()->id, 'subtotal' => 0, 'total' => 0]);
        $owed = Invoice::factory()->overdue(10)->create(['client_id' => $nothing->client_id, 'total' => 1000]);

        $this->artisan('nuvabill:cron')->assertSuccessful();

        $this->assertSame(0, $nothing->fresh()->reminder_count);
        $this->assertSame(1, $owed->fresh()->reminder_count);
    }

    public function test_a_refund_cannot_send_back_more_than_the_recorded_payments(): void
    {
        Mail::fake();
        $this->signInAdmin();
        // Like an invoice imported as paid: 40.00 of it was credit in the old system, 60.00 came in by bank.
        $client = $this->merLas();
        $invoice = Invoice::factory()->for($client)->paid()->create(['total' => 10000, 'subtotal' => 10000]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => 10000]);
        Transaction::query()->create(['client_id' => $client->id, 'invoice_id' => $invoice->id, 'gateway' => 'banktransfer', 'reference' => 'BANK-1', 'type' => 'payment', 'amount' => 6000, 'currency' => 'USD', 'paid_at' => now()]);

        // A full refund is refused before anything happens.
        $this->post(route('admin.invoices.refund', $invoice))->assertSessionHas('error');
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '100.00', 'method' => 'refund'])->assertSessionHasErrors('amount');
        $this->assertSame(0, CreditNote::query()->count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->where('type', 'refund')->count());
        Mail::assertNothingSent();

        // The paid part can be sent back; the rest is settled another way.
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '60.00', 'method' => 'refund'])->assertSessionHas('status');
        $this->assertSame(-6000, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '40.00', 'method' => 'none'])->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_a_refund_the_gateway_only_partly_sent_makes_no_credit_note_for_the_rest(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_part', 'amount' => 1000, 'status' => 'succeeded'])]);
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        $this->signInAdmin();
        $invoice = app(InvoiceManager::class)->create($this->merLas(), [['description' => 'Hosting', 'amount' => 1399]]);
        app(PaymentRecorder::class)->record($invoice, 1399, 'stripe', 'pi_part');

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('error');

        $this->assertSame(0, CreditNote::query()->count(), 'No credit note says 13.99 went back when only 10.00 did.');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(-1000, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));

        // What was sent is recorded without asking the gateway again, and the rest is settled another way.
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '10.00', 'method' => 'refund', 'through_gateway' => '1'])->assertSessionHas('status');
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '3.99', 'method' => 'none'])->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => (int) $request['amount'] === 1399);
    }
}
