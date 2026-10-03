<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Activity;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates invoices and keeps their totals and numbers correct.
 */
class InvoiceManager
{
    public function __construct(private Taxes $taxes) {}

    /**
     * Tax follows the client's tax rule at the time of the invoice; each line can set "taxed" itself.
     *
     * @param  list<array{type?: string, description: string, amount: int, taxed?: bool, service_id?: int|null, domain_id?: int|null, period_start?: CarbonInterface|string|null, period_end?: CarbonInterface|string|null, billing_key?: string|null}>  $items
     *
     * @throws UniqueConstraintViolationException When a line's billing period is already invoiced.
     */
    public function create(
        Client $client,
        array $items,
        ?CarbonInterface $dueAt = null,
        InvoiceStatus $status = InvoiceStatus::Unpaid,
        ?string $notes = null,
        ?string $currency = null,
    ): Invoice {
        return DB::transaction(function () use ($client, $items, $dueAt, $status, $notes, $currency): Invoice {
            $rule = $this->taxes->ruleFor($client);

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'status' => $status,
                'currency' => $currency ?? $client->currency,
                'issued_at' => today(),
                'due_at' => $dueAt ?? today(),
                'notes' => $notes,
                'tax_name' => $rule?->name,
                'tax_rate' => $rule?->rate,
                'tax_inclusive' => $rule !== null && $this->taxes->inclusive(),
            ]);

            foreach ($items as $item) {
                $invoice->items()->create([
                    'type' => $item['type'] ?? 'manual',
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'taxed' => $rule !== null && $this->taxes->isTaxable($item),
                    'service_id' => $item['service_id'] ?? null,
                    'domain_id' => $item['domain_id'] ?? null,
                    'period_start' => $item['period_start'] ?? null,
                    'period_end' => $item['period_end'] ?? null,
                    // Unique in the database: a second invoice for the same period fails and is rolled back.
                    'billing_key' => $item['billing_key'] ?? null,
                ]);
            }

            $invoice->recalculate();

            if ($status !== InvoiceStatus::Draft) {
                $invoice->number = $this->numberFor($invoice);
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Turn a draft into a real invoice the client can pay.
     */
    public function publish(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return $invoice;
        }

        $invoice->recalculate();
        $invoice->status = InvoiceStatus::Unpaid;
        $invoice->number ??= $this->numberFor($invoice);
        $invoice->issued_at = today();
        $invoice->save();

        Activity::log('invoice.published', "Published invoice {$invoice->number}", $invoice);

        return $invoice;
    }

    /**
     * Cancel an unpaid or draft invoice. Money already paid on it (often from the wallet) goes back
     * into the client's wallet, so nothing stays on a cancelled invoice.
     *
     * @throws RuntimeException When money was paid on it and the client's wallet is in another
     *                          currency with no exchange rate; the invoice is not cancelled then.
     */
    public function cancel(Invoice $invoice): Invoice
    {
        // The row is locked and read again, so a payment that lands at the same time is not overwritten.
        $cancelled = DB::transaction(function () use ($invoice): bool {
            $locked = Invoice::query()->with('client')->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($locked->status, [InvoiceStatus::Unpaid, InvoiceStatus::Draft], true)) {
                return false;
            }

            $this->returnPayments($locked);
            $locked->update(['status' => InvoiceStatus::Cancelled, 'amount_paid' => 0]);
            // The periods on it may be invoiced again.
            $locked->items()->whereNotNull('billing_key')->update(['billing_key' => null]);

            return true;
        });

        $invoice->refresh();

        if ($cancelled) {
            Activity::log('invoice.cancelled', "Cancelled invoice {$invoice->displayNumber()}", $invoice);
            app(PlanChanges::class)->cancelForInvoice($invoice);
        }

        return $invoice;
    }

    /**
     * Put what was paid on an invoice that is being cancelled back into the client's wallet. Each
     * payment gets a matching refund line, so the invoice's payments add up to nothing.
     *
     * @throws RuntimeException When the wallet is in another currency with no exchange rate.
     */
    private function returnPayments(Invoice $invoice): void
    {
        $left = $invoice->amount_paid;

        // Money an add-on already put back for this invoice does not go back twice.
        if ($left > 0 && $invoice->currency === $invoice->client->currency) {
            $left -= (int) CreditTransaction::query()->where('invoice_id', $invoice->id)->where('amount', '>', 0)->sum('amount');
        }

        if ($left <= 0) {
            return;
        }

        $recorder = app(PaymentRecorder::class);
        $number = $invoice->displayNumber();
        $label = __('Returned from cancelled invoice :number', ['number' => $number]);
        $convertedLabel = fn (array $rate): string => __('Returned from cancelled invoice :number (:amount at 1 :from = :rate :to)', ['number' => $number] + $rate);
        $transactions = $invoice->transactions()->get();
        $refunds = $transactions->where('type', 'refund');
        $parts = [];

        foreach ($transactions->where('type', 'payment')->sortBy('id') as $payment) {
            $refunded = -(int) $refunds->filter(fn (Transaction $refund): bool => ($refund->meta['refund_of'] ?? null) === $payment->id)->sum('amount');
            $part = min($left, $payment->amount - $refunded);

            if ($part > 0) {
                $parts[] = [$part, $payment];
                $left -= $part;
            }
        }

        // Paid without a payment line, for example on an imported invoice.
        if ($left > 0) {
            $parts[] = [$left, null];
        }

        foreach ($parts as [$amount, $payment]) {
            if (! $recorder->returnToWallet($invoice, $amount, $payment, $label, $convertedLabel)) {
                throw new RuntimeException(__('This invoice has :amount paid on it, and the client\'s wallet is in :currency with no exchange rate to give it back. Add a rate in Settings → Currencies first.', [
                    'amount' => money($invoice->amount_paid, $invoice->currency),
                    'currency' => $invoice->client->currency,
                ]));
            }
        }
    }

    public function numberFor(Invoice $invoice): string
    {
        $number = setting('billing.invoice_prefix').str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT);
        $candidate = $number;
        $suffix = 2;

        // Imported invoices keep their old numbers, which may look like ours.
        while (Invoice::query()->where('number', $candidate)->whereKeyNot($invoice->id)->exists()) {
            $candidate = $number.'-'.$suffix++;
        }

        return $candidate;
    }
}
