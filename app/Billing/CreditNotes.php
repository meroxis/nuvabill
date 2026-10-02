<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\CreditNote;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Activity;
use App\Support\Locales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Issues credit notes for paid invoices. A credit note takes back all or part of an invoice,
 * with its share of the tax, and says what happened to the money: sent back (through the
 * gateway where it can), added to the client's wallet, or settled another way. When the whole
 * invoice is credited, it is marked Refunded.
 *
 * An invoice that added funds to the wallet put its money there when it was paid. Taking it back
 * takes that money out of the wallet again, so it can only be sent back or settled another way,
 * and only while the wallet still holds it.
 */
class CreditNotes
{
    public function __construct(
        private RefundIssuer $refunds,
        private Wallet $wallet,
        private TemplateMailer $mailer,
        private Affiliates $affiliates,
    ) {}

    /**
     * @param  int  $amount  In minor units, with tax.
     *
     * @throws InvalidArgumentException When the invoice cannot get this credit note.
     * @throws RuntimeException When a gateway refuses the refund; no credit note is made then.
     */
    public function issue(Invoice $invoice, int $amount, string $method, ?string $reason = null, ?Admin $admin = null, bool $throughGateway = true): CreditNote
    {
        $lock = Cache::lock("invoice-credit-{$invoice->id}", 120);

        if (! $lock->get()) {
            throw new RuntimeException(__('A credit note for this invoice is being made. Try again in a minute.'));
        }

        try {
            $invoice = $invoice->fresh(['items', 'client', 'creditNotes']);
            $this->check($invoice, $amount, $method);

            // Money first: funds added to the wallet leave it before they are sent back, so they
            // cannot be spent and refunded both. When the refund fails, they go back into the wallet.
            $fromWallet = $this->fundsToTakeBack($invoice, $amount);

            if ($fromWallet > 0) {
                $this->wallet->change($invoice->client, -$fromWallet, __('Funds taken back from invoice :number', ['number' => $invoice->displayNumber()]), $invoice, $admin);
            }

            // When a gateway refuses the refund, there is no credit note to take back. A refund
            // that stopped halfway (a gateway error) already sent part of the money back.
            if ($method === CreditNote::METHOD_REFUND) {
                $refunded = -(int) $invoice->transactions()->where('type', 'refund')->sum('amount');
                $covered = (int) $invoice->creditNotes->where('method', CreditNote::METHOD_REFUND)->sum('total');
                $toSend = $amount - max(0, $refunded - $covered);

                try {
                    if ($toSend > 0) {
                        $this->refunds->refundAmount($invoice, $toSend, $throughGateway);
                    }
                } catch (Throwable $exception) {
                    $sent = -(int) $invoice->transactions()->where('type', 'refund')->sum('amount') - $refunded;

                    if ($fromWallet - $sent > 0) {
                        $this->wallet->change($invoice->client, $fromWallet - $sent, __('Funds returned: the refund of invoice :number did not go through', ['number' => $invoice->displayNumber()]), $invoice, $admin);
                    }

                    throw $exception;
                }
            }

            $creditNote = DB::transaction(function () use ($invoice, $amount, $method, $reason, $admin): CreditNote {
                $whole = $amount === $invoice->total && $invoice->creditNotes->isEmpty();
                $tax = $invoice->total > 0 ? (int) round($amount * $invoice->tax / $invoice->total) : 0;
                $creditNote = CreditNote::query()->create([
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                    'admin_id' => $admin?->id,
                    'currency' => $invoice->currency,
                    'subtotal' => $amount - $tax,
                    'tax' => $tax,
                    'total' => $amount,
                    'tax_name' => $invoice->tax_name,
                    'tax_rate' => $invoice->tax_rate,
                    'items' => $whole
                        ? $invoice->items->map(fn ($item): array => ['description' => $item->description, 'amount' => (int) $item->amount])->values()->all()
                        : [['description' => $this->lineFor($invoice, $reason), 'amount' => $amount - ($invoice->tax_inclusive ? 0 : $tax)]],
                    'method' => $method,
                    'reason' => filled($reason) ? mb_substr(trim((string) $reason), 0, 500) : null,
                    'issued_at' => now(),
                ]);
                $creditNote->forceFill(['number' => setting('billing.credit_note_prefix').str_pad((string) $creditNote->id, 4, '0', STR_PAD_LEFT)])->save();

                if ($method === CreditNote::METHOD_WALLET) {
                    $this->wallet->change($invoice->client, $amount, __('Credit note :number', ['number' => $creditNote->number]), $invoice, $admin);
                }

                if ($invoice->creditableAmount() - $amount <= 0) {
                    $invoice->update(['status' => InvoiceStatus::Refunded]);
                }

                return $creditNote;
            });
        } finally {
            $lock->release();
        }

        if ($invoice->fresh()->status === InvoiceStatus::Refunded) {
            $this->affiliates->cancelForRefund($invoice);
        }

        Activity::log('credit_note.issued', "Credit note {$creditNote->number} issued for invoice {$invoice->displayNumber()}", $invoice, actor: $admin);
        rescue(fn () => $this->mailer->send('invoice.credit_note', $invoice->client, TemplateMailer::invoiceContext($invoice) + [
            'credit_note' => Locales::in(Locales::forClient($invoice->client), fn (): array => [
                'number' => $creditNote->number,
                'total' => money($creditNote->total, $creditNote->currency),
                'reason' => (string) $creditNote->reason,
                'note' => match ($method) {
                    CreditNote::METHOD_REFUND => __('We are sending the money back the way you paid. It can take a few days to arrive.'),
                    CreditNote::METHOD_WALLET => __('We added the money to your wallet. It pays your next invoices.'),
                    default => '',
                },
            ]),
        ]));

        return $creditNote;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function check(Invoice $invoice, int $amount, string $method): void
    {
        if ($invoice->status !== InvoiceStatus::Paid) {
            throw new InvalidArgumentException(__('Only paid invoices can get a credit note.'));
        }

        if (! in_array($method, CreditNote::METHODS, true)) {
            throw new InvalidArgumentException(__('Choose what happens to the money.'));
        }

        $creditable = $invoice->creditableAmount();

        if ($amount < 1 || $amount > $creditable) {
            throw new InvalidArgumentException(__('The credit note can be at most :amount.', ['amount' => money($creditable, $invoice->currency)]));
        }

        if ($method === CreditNote::METHOD_WALLET && $invoice->currency !== $invoice->client->currency) {
            throw new InvalidArgumentException(__('The client\'s wallet is in :currency, so this credit cannot go into it.', ['currency' => $invoice->client->currency]));
        }

        if ($this->fundsAddedBy($invoice) === 0) {
            return;
        }

        if ($method === CreditNote::METHOD_WALLET) {
            throw new InvalidArgumentException(__('This invoice added funds to the wallet, so the money is already there. Send it back or settle it yourself instead.'));
        }

        $credit = (int) $invoice->client->fresh()->credit;

        if ($this->fundsToTakeBack($invoice, $amount) > $credit) {
            throw new InvalidArgumentException(__('The client already spent part of these funds. Their wallet holds :amount, so take back at most that much.', ['amount' => money($credit, $invoice->client->currency)]));
        }
    }

    /**
     * The funds this invoice added to the wallet when it was paid, in minor units.
     */
    private function fundsAddedBy(Invoice $invoice): int
    {
        return (int) $invoice->items->where('type', InvoiceItem::TYPE_CREDIT)->where('amount', '>', 0)->sum('amount');
    }

    /**
     * The part of a credit note that takes back wallet funds.
     */
    private function fundsPartOf(Invoice $invoice, int $amount): int
    {
        $funds = $this->fundsAddedBy($invoice);

        return $funds > 0 && $invoice->total > 0 ? min($amount, (int) round($amount * $funds / $invoice->total)) : 0;
    }

    /**
     * How much the credit note still has to take out of the wallet. A credit note that stopped
     * halfway (a database error after the refund was sent) already took its part out.
     */
    private function fundsToTakeBack(Invoice $invoice, int $amount): int
    {
        $part = $this->fundsPartOf($invoice, $amount);

        if ($part === 0) {
            return 0;
        }

        $covered = (int) $invoice->creditNotes
            ->where('method', '!=', CreditNote::METHOD_WALLET)
            ->sum(fn (CreditNote $creditNote): int => $this->fundsPartOf($invoice, $creditNote->total));
        $inWallet = (int) CreditTransaction::query()->where('invoice_id', $invoice->id)->sum('amount');
        $alreadyTaken = max(0, $this->fundsAddedBy($invoice) - $covered - $inWallet);

        return max(0, $part - $alreadyTaken);
    }

    private function lineFor(Invoice $invoice, ?string $reason): string
    {
        return Locales::in(Locales::forClient($invoice->client), fn (): string => filled($reason)
            ? __('Credit for invoice :number: :reason', ['number' => $invoice->displayNumber(), 'reason' => trim((string) $reason)])
            : __('Credit for invoice :number', ['number' => $invoice->displayNumber()]));
    }
}
