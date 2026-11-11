<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Activity;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates invoices and keeps their totals and numbers correct.
 */
class InvoiceManager
{
    public function __construct(private Taxes $taxes) {}

    /**
     * Tax follows the client's tax rule at the time of the invoice; each line can set "taxed" itself.
     *
     * @param  list<array{type?: string, description: string, amount: int, taxed?: bool, service_id?: int|null, domain_id?: int|null, service_resource_option_id?: int|null, period_start?: CarbonInterface|string|null, period_end?: CarbonInterface|string|null, billing_key?: string|null}>  $items  service_resource_option_id says which of the service's extras a resource line pays for.
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
                    // Cut to the column, so one long product name or domain cannot stop invoicing on MySQL.
                    'description' => mb_substr((string) $item['description'], 0, LineItems::DESCRIPTION_MAX),
                    'amount' => $item['amount'],
                    'taxed' => $rule !== null && $this->taxes->isTaxable($item),
                    'service_id' => $item['service_id'] ?? null,
                    'domain_id' => $item['domain_id'] ?? null,
                    'service_resource_option_id' => $item['service_resource_option_id'] ?? null,
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
     * into the client's wallet, so nothing stays on a cancelled invoice. A coupon used on it gets
     * that use back.
     *
     * @throws NoExchangeRate When money was paid on it and the client's wallet is in another
     *                        currency with no exchange rate; the invoice is not cancelled then.
     */
    public function cancel(Invoice $invoice): Invoice
    {
        return $this->close($invoice, giveBack: true);
    }

    /**
     * Cancel an unpaid invoice whose paid part cannot go back into the client's wallet, because the
     * wallet is in another currency with no exchange rate. What was paid stays on the cancelled
     * invoice for staff to give back by hand, the same as a late payment on a closed invoice. The
     * caller tells staff. Anything that can go back to the wallet uses cancel() instead.
     */
    public function cancelKeepingPayments(Invoice $invoice): Invoice
    {
        return $this->close($invoice, giveBack: false);
    }

    /**
     * @throws NoExchangeRate When the money should go back and cannot; nothing changes then.
     */
    private function close(Invoice $invoice, bool $giveBack): Invoice
    {
        // The row is locked and read again, so a payment that lands at the same time is not overwritten.
        $cancelled = DB::transaction(function () use ($invoice, $giveBack): bool {
            $locked = Invoice::query()->with('client')->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($locked->status, [InvoiceStatus::Unpaid, InvoiceStatus::Draft], true)) {
                return false;
            }

            if ($giveBack) {
                $this->returnPayments($locked);
                $locked->amount_paid = 0;
            }

            $locked->status = InvoiceStatus::Cancelled;
            $locked->save();
            // The periods on it may be invoiced again.
            $locked->items()->whereNotNull('billing_key')->update(['billing_key' => null]);
            $this->returnCouponUses($locked);

            return true;
        });

        $invoice->refresh();

        if ($cancelled) {
            Activity::log('invoice.cancelled', "Cancelled invoice {$invoice->displayNumber()}", $invoice);
            app(PlanChanges::class)->cancelForInvoice($invoice);
            app(ResourceChanges::class)->cancelForInvoice($invoice);
        }

        return $invoice;
    }

    /**
     * A cancelled invoice gave no discount, so the coupon uses made for it are free again: for the
     * coupon's own limit and for the limit per client. Run inside the transaction that cancels the
     * invoice, with its row locked. A use is given back by whoever deletes its row, so never twice,
     * and the count never goes below zero.
     */
    private function returnCouponUses(Invoice $invoice): void
    {
        $redemptions = CouponRedemption::query()->where('invoice_id', $invoice->id)->orderBy('id')->get(['id', 'coupon_id', 'order_id']);

        foreach ($redemptions as $redemption) {
            if (CouponRedemption::query()->whereKey($redemption->id)->delete() !== 1) {
                continue;
            }

            // Only orders count towards a coupon's uses; a renewal that kept the discount never did.
            if ($redemption->order_id !== null) {
                Coupon::query()->whereKey($redemption->coupon_id)->where('uses', '>', 0)->decrement('uses');
            }
        }
    }

    /**
     * Put what was paid on an invoice that is being cancelled back into the client's wallet. Each
     * payment gets a matching refund line, so the invoice's payments add up to nothing.
     *
     * @throws NoExchangeRate When the wallet is in another currency with no exchange rate.
     */
    private function returnPayments(Invoice $invoice): void
    {
        $left = $invoice->amount_paid;

        // Money an add-on already put back for this invoice does not go back twice.
        if ($left > 0 && $invoice->currency === $invoice->client->currency) {
            $left -= (int) CreditTransaction::query()->where('invoice_id', $invoice->id)->where('amount', '>', 0)->sum('amount');
        }

        // Money a payment service already sent back itself (a refund made there, or a dispute) is
        // still counted in the amount paid. It does not go into the wallet as well.
        if ($left > 0) {
            $left += (int) Transaction::query()->where('invoice_id', $invoice->id)->where('type', 'refund')->get(['amount', 'meta'])
                ->filter(fn (Transaction $refund): bool => ($refund->meta['reported_by_gateway'] ?? false) === true)
                ->sum('amount');
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
                throw new NoExchangeRate(__('This invoice has :amount paid on it, and the client\'s wallet is in :currency with no exchange rate to give it back. Add a rate in Settings → Currencies first.', [
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
