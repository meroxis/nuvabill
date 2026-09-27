<?php

namespace Tests\Feature\Billing;

use App\Enums\QuoteStatus;
use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\TaxRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QuotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_send_a_quote_and_the_client_accepts_it_into_an_invoice(): void
    {
        Mail::fake();
        $this->setSettings(['tax.enabled' => true]);
        $rule = TaxRule::factory()->create(['name' => 'VAT', 'rate' => 2000, 'country' => 'GB']);
        $client = Client::factory()->create(['country' => 'GB', 'email' => 'raz@razstudio.test']);
        $this->signInAdmin();

        $this->post(route('admin.quotes.store'), [
            'client' => 'raz@razstudio.test',
            'subject' => 'Dedicated server setup',
            'valid_until' => today()->addDays(14)->toDateString(),
            'notes' => 'Ready in two days.',
            'items' => [
                ['description' => 'Server setup', 'amount' => '200.00', 'taxed' => '1'],
                ['description' => 'Hardware deposit', 'amount' => '100.00', 'taxed' => '0'],
            ],
            'send' => '1',
        ])->assertRedirect();

        $quote = Quote::query()->sole();
        $this->assertSame(QuoteStatus::Sent, $quote->status);
        $this->assertSame('Q-'.str_pad((string) $quote->id, 4, '0', STR_PAD_LEFT), $quote->number);
        $this->assertSame(4000, $quote->tax);
        $this->assertSame(34000, $quote->total);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, $quote->number) && $mail->hasTo('raz@razstudio.test'));

        $rule->update(['rate' => 500]);

        $this->actingAs($client, 'web')->get(route('client.quotes.show', $quote))->assertOk()->assertSee('Dedicated server setup')->assertSee('$340.00')->assertSee('Accept quote');
        $this->post(route('client.quotes.accept', $quote))->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame(4000, $invoice->tax, 'The invoice charges what the quote said, not the new rate.');
        $this->assertSame(34000, $invoice->total);
        $this->assertSame(QuoteStatus::Accepted, $quote->fresh()->status);
        $this->assertSame($invoice->id, $quote->fresh()->invoice_id);
        $this->post(route('client.quotes.accept', $quote))->assertSessionHas('error');
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_clients_decline_quotes_and_cannot_accept_expired_or_other_peoples_quotes(): void
    {
        $client = Client::factory()->create();
        $sent = Quote::factory()->for($client)->create(['status' => QuoteStatus::Sent, 'number' => 'Q-0001']);
        $expired = Quote::factory()->for($client)->create(['status' => QuoteStatus::Sent, 'number' => 'Q-0002', 'valid_until' => today()->subDay()]);
        $draft = Quote::factory()->for($client)->create();
        $someoneElses = Quote::factory()->create(['status' => QuoteStatus::Sent, 'number' => 'Q-0004']);

        $this->actingAs($client, 'web');
        $this->get(route('client.quotes.index'))->assertSee('Q-0001')->assertSee('Q-0002')->assertDontSee('Draft quote');
        $this->get(route('client.quotes.show', $draft))->assertNotFound();
        $this->get(route('client.quotes.show', $someoneElses))->assertNotFound();

        $this->get(route('client.quotes.show', $expired))->assertSee('Expired')->assertDontSee('Accept quote');
        $this->post(route('client.quotes.accept', $expired))->assertSessionHas('error');

        $this->post(route('client.quotes.decline', $sent))->assertSessionHas('status');
        $this->assertSame(QuoteStatus::Declined, $sent->fresh()->status);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_staff_edit_open_quotes_delete_drafts_and_download_pdfs(): void
    {
        $this->signInAdmin();
        $client = Client::factory()->create();
        $draft = Quote::factory()->for($client)->create();
        $accepted = Quote::factory()->for($client)->create(['status' => QuoteStatus::Accepted, 'number' => 'Q-0009']);

        $this->put(route('admin.quotes.update', $draft), [
            'client' => (string) $client->id,
            'subject' => 'Migration from another host',
            'valid_until' => today()->addDays(30)->toDateString(),
            'items' => [['description' => 'Migration', 'amount' => '49.00']],
            'send' => '0',
        ])->assertRedirect(route('admin.quotes.show', $draft));
        $this->assertSame(4900, $draft->fresh()->total);

        $this->get(route('admin.quotes.edit', $accepted))->assertRedirect(route('admin.quotes.show', $accepted));
        $this->get(route('admin.quotes.pdf', $draft))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($client, 'web')->get(route('client.quotes.pdf', $accepted))->assertOk();

        $this->signInAdmin();
        $this->delete(route('admin.quotes.destroy', $accepted))->assertSessionHas('error');
        $this->delete(route('admin.quotes.destroy', $draft))->assertRedirect(route('admin.quotes.index'));
        $this->assertSame(1, Quote::query()->count());
        $this->get(route('admin.quotes.index'))->assertOk()->assertSee('Q-0009');
    }
}
