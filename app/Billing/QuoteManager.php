<?php

namespace App\Billing;

use App\Enums\QuoteStatus;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Activity;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes quotes, sends them, and turns accepted ones into invoices.
 *
 * A quote keeps the tax rule the client had when it was written, and the invoice made from it
 * charges exactly what the client saw, even if tax rules changed in between.
 */
class QuoteManager
{
    public function __construct(
        private Taxes $taxes,
        private InvoiceManager $invoices,
        private TemplateMailer $mailer,
    ) {}

    /**
     * Create or change a quote.
     *
     * @param  array{subject: string, valid_until: CarbonImmutable|string, notes?: string|null, admin_notes?: string|null}  $data
     * @param  list<array{description: string, amount: int, taxed?: bool}>  $items
     */
    public function save(?Quote $quote, Client $client, array $data, array $items): Quote
    {
        if ($quote !== null && ! $quote->isEditable()) {
            throw new RuntimeException(__('Accepted or declined quotes cannot be changed.'));
        }

        return DB::transaction(function () use ($quote, $client, $data, $items): Quote {
            $rule = $this->taxes->ruleFor($client);
            $quote ??= new Quote(['status' => QuoteStatus::Draft]);

            $quote->fill([
                'client_id' => $client->id,
                'subject' => $data['subject'],
                'valid_until' => $data['valid_until'],
                'notes' => $data['notes'] ?? null,
                'admin_notes' => $data['admin_notes'] ?? null,
                'currency' => $client->currency,
                'tax_name' => $rule?->name,
                'tax_rate' => $rule?->rate,
                'tax_inclusive' => $rule !== null && $this->taxes->inclusive(),
            ])->save();

            $quote->items()->delete();

            foreach ($items as $item) {
                $quote->items()->create([
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'taxed' => $rule !== null && ($item['taxed'] ?? true),
                ]);
            }

            $quote->recalculate();
            $quote->save();

            return $quote;
        });
    }

    /**
     * Email the quote to the client. It gets its number now, and can then be accepted.
     */
    public function send(Quote $quote): Quote
    {
        if (! $quote->isEditable()) {
            throw new RuntimeException(__('Accepted or declined quotes cannot be sent again.'));
        }

        $quote->number ??= $this->numberFor($quote);
        $quote->status = QuoteStatus::Sent;
        $quote->sent_at = now();
        $quote->save();

        $this->mailer->send('quote.sent', $quote->client, self::context($quote));
        Activity::log('quote.sent', "Sent quote {$quote->number}: {$quote->subject}", $quote, $quote->client);

        return $quote;
    }

    /**
     * The client accepts: an invoice is created with the quote's lines and tax.
     */
    public function accept(Quote $quote): Invoice
    {
        $invoice = DB::transaction(function () use ($quote): Invoice {
            $quote = Quote::query()->lockForUpdate()->with('items', 'client')->findOrFail($quote->id);

            if (! $quote->canBeAccepted()) {
                throw new RuntimeException($quote->isPastValidDate() ? __('This quote has expired. Ask us for a new one.') : __('This quote can no longer be accepted.'));
            }

            $invoice = $this->invoices->create(
                $quote->client,
                $quote->items->map(fn ($item): array => ['description' => $item->description, 'amount' => $item->amount, 'taxed' => $item->taxed])->all(),
                dueAt: CarbonImmutable::today()->addDays((int) setting('billing.payment_terms_days')),
                notes: __('For quote :number: :subject', ['number' => $quote->number, 'subject' => $quote->subject]),
                currency: $quote->currency,
            );

            // Charge exactly what the quote said, even if the tax rules changed since.
            $invoice->forceFill(['tax_name' => $quote->tax_name, 'tax_rate' => $quote->tax_rate, 'tax_inclusive' => $quote->tax_inclusive]);
            $invoice->items->each(fn ($line, int $index) => $line->update(['taxed' => $quote->tax_rate !== null && (bool) $quote->items[$index]->taxed]));
            $invoice->recalculate();
            $invoice->save();

            $quote->update(['status' => QuoteStatus::Accepted, 'accepted_at' => now(), 'invoice_id' => $invoice->id]);

            return $invoice;
        });

        $quote->refresh();
        Activity::log('quote.accepted', "Quote {$quote->number} accepted; invoice {$invoice->number} created", $quote, $quote->client);
        $this->mailer->send('invoice.created', $quote->client, TemplateMailer::invoiceContext($invoice));
        $this->mailer->sendToStaff('admin.quote_accepted', self::context($quote) + [
            'invoice' => ['number' => $invoice->number],
            'admin_url' => route('admin.quotes.show', $quote),
        ]);
        app(Wallet::class)->applyAutomatically($invoice);

        return $invoice->refresh();
    }

    public function decline(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::Sent) {
            throw new RuntimeException(__('This quote can no longer be declined.'));
        }

        $quote->update(['status' => QuoteStatus::Declined, 'declined_at' => now()]);
        Activity::log('quote.declined', "Quote {$quote->number} declined by the client", $quote, $quote->client);

        return $quote;
    }

    /**
     * Placeholders for quote emails.
     *
     * @return array<string, mixed>
     */
    public static function context(Quote $quote): array
    {
        return [
            'client' => TemplateMailer::clientContext($quote->client),
            'quote' => [
                'number' => $quote->displayNumber(),
                'subject' => $quote->subject,
                'total' => money($quote->total, $quote->currency),
                'valid_until' => Locales::in(Locales::forClient($quote->client), fn (): string => $quote->valid_until->translatedFormat('d M Y')),
                'url' => route('client.quotes.show', $quote),
            ],
        ];
    }

    private function numberFor(Quote $quote): string
    {
        $number = setting('quotes.prefix').str_pad((string) $quote->id, 4, '0', STR_PAD_LEFT);
        $candidate = $number;
        $suffix = 2;

        while (Quote::query()->where('number', $candidate)->whereKeyNot($quote->id)->exists()) {
            $candidate = $number.'-'.$suffix++;
        }

        return $candidate;
    }
}
