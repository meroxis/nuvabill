<?php

namespace Tests\Feature\Billing;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    private function paidInvoice(int $total, int $tax = 0, string $currency = 'USD'): Invoice
    {
        $client = Client::factory()->create(['currency' => 'USD']);
        $invoice = Invoice::factory()->for($client)->create(['total' => $total, 'subtotal' => $total - $tax, 'tax' => $tax, 'currency' => $currency]);
        $invoice->items()->create(['description' => 'Hosting', 'amount' => $total - $tax]);
        app(PaymentRecorder::class)->record($invoice, $total, 'banktransfer', 'BANK-'.$invoice->id);

        return $invoice->fresh();
    }
}
