<?php

namespace Tests\Feature\Billing;

use App\Billing\CreditNotes;
use App\Billing\PaymentRecorder;
use App\Billing\RefundIssuer;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Credit notes: numbered documents that take back all or part of a paid invoice.
 */
class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
        Http::fake();
        Mail::fake();
    }

    public function test_a_partial_credit_goes_to_the_wallet_and_keeps_the_invoice_paid(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = $this->paidInvoice(2000, tax: 200);

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '5.00', 'method' => 'wallet', 'reason' => 'One day of downtime'])
            ->assertSessionHas('status', 'Credit note CN-0001 was made.');

        $creditNote = CreditNote::query()->sole();
        $this->assertSame([500, 50, 450], [$creditNote->total, $creditNote->tax, $creditNote->subtotal]);
        $this->assertSame('One day of downtime', $creditNote->reason);
        $this->assertSame(500, $invoice->client->fresh()->credit);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1500, $invoice->fresh()->creditableAmount());
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === "Credit note CN-0001 for invoice {$invoice->number}");

        // The rest, settled outside Nuvabill, credits the invoice in full.
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '15.00', 'method' => 'none'])->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->creditableAmount());
        $this->assertSame(500, $invoice->client->fresh()->credit);
    }

    public function test_a_credit_cannot_be_more_than_what_is_left(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = $this->paidInvoice(1000);

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '10.01', 'method' => 'none'])
            ->assertSessionHasErrors('amount');

        $unpaid = Invoice::factory()->create();
        $this->post(route('admin.invoices.credit-notes.store', $unpaid), ['amount' => '1.00', 'method' => 'none'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, CreditNote::query()->count());
    }

    public function test_a_refund_sends_the_money_back_and_writes_a_credit_note(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = $this->paidInvoice(1000);

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '4.00', 'method' => 'refund'])->assertSessionHas('status');
        $this->assertSame(-400, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));

        $this->post(route('admin.invoices.refund', $invoice))->assertSessionHas('status', 'Invoice refunded. Credit note CN-0002 was made.');
        $this->assertSame(-1000, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->assertSame([400, 600], CreditNote::query()->orderBy('id')->pluck('total')->all());
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_money_in_another_currency_cannot_go_into_the_wallet(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = $this->paidInvoice(1000, currency: 'EUR');

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '1.00', 'method' => 'wallet'])
            ->assertSessionHasErrors('amount');
    }

    public function test_clients_download_their_own_credit_notes_only(): void
    {
        $invoice = $this->paidInvoice(1000);
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '10.00', 'method' => 'none']);
        $creditNote = CreditNote::query()->sole();

        $this->get(route('admin.credit-notes.index'))->assertOk()->assertSee('CN-0001');
        $this->get(route('admin.credit-notes.pdf', $creditNote))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($invoice->client, 'web')->get(route('client.invoices.show', $invoice))->assertOk()->assertSee('CN-0001');
        $this->get(route('client.credit-notes.pdf', $creditNote))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs(Client::factory()->create(), 'web')->get(route('client.credit-notes.pdf', $creditNote))->assertNotFound();
    }

    public function test_funds_added_to_the_wallet_are_not_credited_to_the_wallet_again(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = $this->paidTopUp(1000);

        $this->get(route('admin.invoices.show', $this->paidInvoice(1000)))->assertOk()->assertSee('Add it to the client&#039;s wallet', false);
        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertDontSee('Add it to the client&#039;s wallet', false);

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '10.00', 'method' => 'wallet'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(1000, $invoice->client->fresh()->credit);
        $this->assertSame(0, CreditNote::query()->count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    public function test_refunding_added_funds_takes_them_out_of_the_wallet(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = $this->paidTopUp(1000);

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '4.00', 'method' => 'refund'])->assertSessionHas('status');
        $this->assertSame(600, $invoice->client->fresh()->credit);
        $this->assertSame(-400, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        $this->post(route('admin.invoices.refund', $invoice))->assertSessionHas('status');
        $this->assertSame(0, $invoice->client->fresh()->credit);
        $this->assertSame(-1000, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertSame([1000, -400, -600], CreditTransaction::query()->orderBy('id')->pluck('amount')->all());
    }

    public function test_funds_the_client_already_spent_cannot_be_taken_back(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = $this->paidTopUp(1000);
        app(Wallet::class)->change($invoice->client, -700, 'Paid another invoice');

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '10.00', 'method' => 'refund'])
            ->assertSessionHasErrors(['amount' => 'The client already spent part of these funds. Their wallet holds $3.00, so take back at most that much.']);
        $this->assertSame(0, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->assertSame(300, $invoice->client->fresh()->credit);

        // What is left can still be settled another way.
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '3.00', 'method' => 'none'])->assertSessionHas('status');
        $this->assertSame(0, $invoice->client->fresh()->credit);
        $this->assertSame(700, $invoice->fresh()->creditableAmount());
    }

    public function test_a_refused_refund_puts_the_funds_back_into_the_wallet(): void
    {
        $invoice = $this->paidTopUp(1000);
        $this->mock(RefundIssuer::class)->shouldReceive('refundAmount')->andThrow(new RuntimeException('Card closed'));

        try {
            app(CreditNotes::class)->issue($invoice, 1000, CreditNote::METHOD_REFUND);
            $this->fail('The refund should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Card closed', $exception->getMessage());
        }

        $this->assertSame(1000, $invoice->client->fresh()->credit);
        $this->assertSame([1000, -1000, 1000], CreditTransaction::query()->orderBy('id')->pluck('amount')->all());
        $this->assertSame(0, CreditNote::query()->count());
    }

    private function paidTopUp(int $amount): Invoice
    {
        $this->setSettings(['wallet.enabled' => true]);
        $invoice = app(Wallet::class)->topUp(Client::factory()->create(['currency' => 'USD']), $amount);
        app(PaymentRecorder::class)->record($invoice, $amount, 'banktransfer', 'BANK-'.$invoice->id);

        return $invoice->fresh();
    }

    private function paidInvoice(int $total, int $tax = 0, string $currency = 'USD'): Invoice
    {
        $client = Client::factory()->create(['currency' => 'USD']);
        $invoice = Invoice::factory()->for($client)->create(['total' => $total, 'subtotal' => $total - $tax, 'tax' => $tax, 'currency' => $currency]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $total - $tax]);
        app(PaymentRecorder::class)->record($invoice, $total, 'banktransfer', 'BANK-'.$invoice->id);

        return $invoice->fresh();
    }
}
