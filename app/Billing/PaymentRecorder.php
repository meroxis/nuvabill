<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Events\InvoicePaid;
use App\Extensions\Gateways\PaymentResult;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Activity;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records money received against an invoice. When an invoice becomes fully paid,
 * {@see InvoicePaidHandler} activates, renews or unsuspends the services on it.
 */
class PaymentRecorder
{
    public function __construct(private InvoicePaidHandler $paidHandler) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        Invoice $invoice,
        int $amount,
        string $gateway,
        ?string $reference = null,
        int $fee = 0,
        array $meta = [],
        ?CarbonInterface $paidAt = null,
    ): Transaction {
        $existing = fn (): ?Transaction => $reference === null ? null : Transaction::query()->where('gateway', $gateway)->where('reference', $reference)->first();

        if (($found = $existing()) !== null) {
            return $found;
        }

        try {
            [$transaction, $becamePaid] = DB::transaction(fn (): array => $this->store($invoice, $amount, $gateway, $reference, $fee, $meta, $paidAt));
        } catch (UniqueConstraintViolationException $exception) {
            // The same payment arrived twice at once (for example a webhook and the return page);
            // the gateway reference is unique, so the first one counts and this one changes nothing.
            return $existing() ?? throw $exception;
        }

        $this->afterRecorded($invoice, $amount, $gateway, $becamePaid);

        return $transaction;
    }

    /**
     * Save the payment and update the invoice. Call it inside a database transaction; the invoice
     * row is locked until that transaction ends. Then call afterRecorded().
     *
     * @param  array<string, mixed>  $meta
     * @return array{0: Transaction, 1: bool} The transaction, and whether the invoice became paid.
     */
    public function store(Invoice $invoice, int $amount, string $gateway, ?string $reference = null, int $fee = 0, array $meta = [], ?CarbonInterface $paidAt = null): array
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

        $transaction = $invoice->transactions()->create([
            'client_id' => $invoice->client_id,
            'gateway' => $gateway,
            'reference' => $reference,
            'type' => 'payment',
            'amount' => $amount,
            'fee' => $fee,
            'currency' => $invoice->currency,
            'meta' => $meta ?: null,
            'paid_at' => $paidAt ?? now(),
        ]);

        $invoice->amount_paid += $amount;
        $becamePaid = false;

        if ($invoice->status === InvoiceStatus::Unpaid && $invoice->amount_paid >= $invoice->total) {
            $invoice->status = InvoiceStatus::Paid;
            $invoice->paid_at = $transaction->paid_at;
            $invoice->payment_method = $gateway;
            $becamePaid = true;

            $overpaid = $invoice->amount_paid - $invoice->total;

            if ($overpaid > 0) {
                app(Wallet::class)->change($invoice->client, $overpaid, __('Overpayment on invoice :number', ['number' => $invoice->displayNumber()]), $invoice);
            }
        }

        $invoice->save();

        return [$transaction, $becamePaid];
    }

    /**
     * Log the payment and, when the invoice became paid, activate or renew what it paid for. Runs
     * after the transaction of store() has ended, so slow server calls do not hold the lock.
     */
    public function afterRecorded(Invoice $invoice, int $amount, string $gateway, bool $becamePaid): void
    {
        $invoice->refresh();

        Activity::log('payment.received', 'Payment of '.money($amount, $invoice->currency)." received for invoice {$invoice->number} via {$gateway}", $invoice);

        if ($becamePaid) {
            $this->paidHandler->handle($invoice);
            InvoicePaid::dispatch($invoice);
        }
    }

    /**
     * Record a payment confirmed by a gateway (return page or webhook). Safe to call twice for the same payment.
     */
    public function recordGatewayResult(PaymentResult $result, string $gateway): ?Transaction
    {
        $invoice = Invoice::find($result->invoiceId);

        if ($invoice === null) {
            Log::warning("Gateway {$gateway} reported a payment for unknown invoice {$result->invoiceId}.");

            return null;
        }

        if (strtoupper($result->currency) !== $invoice->currency || $result->amount <= 0) {
            Log::warning("Gateway {$gateway} reported {$result->amount} {$result->currency} for invoice {$invoice->id} in {$invoice->currency}; not recorded.");

            return null;
        }

        return $this->record($invoice, $result->amount, $gateway, $result->reference, $result->fee, $result->meta);
    }

    /**
     * Mark a zero-total invoice (for example a free product) as paid without a transaction.
     */
    public function settleFreeInvoice(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Unpaid || $invoice->total > 0) {
            return;
        }

        $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
        $this->paidHandler->handle($invoice);
        InvoicePaid::dispatch($invoice);
    }
}
