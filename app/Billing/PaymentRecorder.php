<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Events\InvoicePaid;
use App\Extensions\Gateways\PaymentResult;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Activity;
use Carbon\CarbonInterface;
use Closure;
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

        // A payment that reaches a cancelled or refunded invoice (a gateway that confirmed late)
        // goes to the wallet, so it is not left on an invoice nobody can pay or refund.
        if (in_array($invoice->status, [InvoiceStatus::Cancelled, InvoiceStatus::Refunded], true)) {
            $this->creditClosedInvoicePayment($invoice, $transaction);
            $invoice->save();

            return [$transaction, false];
        }

        $paidBefore = $invoice->amount_paid;
        $invoice->amount_paid += $amount;
        $becamePaid = false;

        if ($invoice->status === InvoiceStatus::Unpaid && $invoice->amount_paid >= $invoice->total) {
            $invoice->status = InvoiceStatus::Paid;
            $invoice->paid_at = $transaction->paid_at;
            $invoice->payment_method = $gateway;
            $becamePaid = true;
        }

        // Money beyond the total goes into the wallet: from the payment that settled the invoice and
        // from every payment after it. Only the new surplus counts, so nothing is credited twice.
        $surplus = max(0, $invoice->amount_paid - $invoice->total) - max(0, $paidBefore - $invoice->total);

        if ($surplus > 0 && $invoice->status === InvoiceStatus::Paid) {
            $this->creditSurplus($invoice, $surplus);
        }

        $invoice->save();

        return [$transaction, $becamePaid];
    }

    /**
     * Put an overpayment into the client's wallet. A wallet in another currency gets the amount at
     * the exchange rate staff set, and its line says the amount and rate used. Without a rate the
     * money stays on the invoice for staff to settle, and the activity log says so.
     */
    private function creditSurplus(Invoice $invoice, int $surplus): void
    {
        $number = $invoice->displayNumber();
        $entry = $this->toWallet(
            $invoice,
            $surplus,
            __('Overpayment on invoice :number', ['number' => $number]),
            fn (array $rate): string => __('Overpayment on invoice :number (:amount at 1 :from = :rate :to)', ['number' => $number] + $rate),
        );

        if ($entry === null) {
            Activity::log('payment.overpaid', 'Overpayment of '.money($surplus, $invoice->currency)." on invoice {$number} was not added to the {$invoice->client->currency} wallet: there is no exchange rate. Settle it by hand.", $invoice);
        }
    }

    /**
     * A payment on a cancelled or refunded invoice goes into the client's wallet, with a refund
     * line that matches it. Without an exchange rate for the wallet it stays on the invoice for
     * staff to settle, and the activity log says so.
     */
    private function creditClosedInvoicePayment(Invoice $invoice, Transaction $payment): void
    {
        $number = $invoice->displayNumber();
        $amount = money($payment->amount, $invoice->currency);
        $returned = $this->returnToWallet(
            $invoice,
            $payment->amount,
            $payment,
            __('Payment on closed invoice :number', ['number' => $number]),
            fn (array $rate): string => __('Payment on closed invoice :number (:amount at 1 :from = :rate :to)', ['number' => $number] + $rate),
        );

        if ($returned) {
            Activity::log('payment.on_closed_invoice', "Payment of {$amount} arrived on {$invoice->status->value} invoice {$number} and was added to the client's wallet.", $invoice);

            return;
        }

        $invoice->amount_paid += $payment->amount;
        Activity::log('payment.on_closed_invoice', "Payment of {$amount} arrived on {$invoice->status->value} invoice {$number} and was not added to the {$invoice->client->currency} wallet: there is no exchange rate. Settle it by hand.", $invoice);
    }

    /**
     * Give money paid on an invoice back into the client's wallet. With a payment, a refund line
     * that matches it is written too, so the invoice's payments add up to what it kept. A wallet
     * in another currency gets the amount at the exchange rate staff set.
     *
     * @param  int  $amount  In minor units of the invoice's currency.
     * @param  Closure(array{amount: string, from: string, rate: string, to: string}): string  $convertedLabel  The wallet line when the amount is converted.
     * @return bool False when the wallet is in another currency with no exchange rate; nothing changes then.
     */
    public function returnToWallet(Invoice $invoice, int $amount, ?Transaction $payment, string $label, Closure $convertedLabel): bool
    {
        $entry = $this->toWallet($invoice, $amount, $label, $convertedLabel);

        if ($entry === null) {
            return false;
        }

        if ($payment !== null) {
            Transaction::create([
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'gateway' => Wallet::GATEWAY,
                'reference' => 'wallet-'.$entry->id,
                'type' => 'refund',
                'amount' => -$amount,
                'currency' => $invoice->currency,
                'meta' => ['refund_of' => $payment->id, 'through_gateway' => true],
                'paid_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Add money from an invoice to the client's wallet, at the exchange rate staff set when the
     * wallet is in another currency. Returns null, and adds nothing, when there is no rate.
     *
     * @param  Closure(array{amount: string, from: string, rate: string, to: string}): string  $convertedLabel
     */
    private function toWallet(Invoice $invoice, int $amount, string $label, Closure $convertedLabel): ?CreditTransaction
    {
        $client = $invoice->client;

        if ($invoice->currency === $client->currency) {
            return app(Wallet::class)->change($client, $amount, $label, $invoice);
        }

        $rates = app(ExchangeRates::class);
        $rate = $rates->rate($invoice->currency, $client->currency);
        $converted = $rates->convert($amount, $invoice->currency, $client->currency);

        if ($rate === null || $converted === null || $converted <= 0) {
            return null;
        }

        return app(Wallet::class)->change($client, $converted, $convertedLabel([
            'amount' => money($amount, $invoice->currency),
            'from' => $invoice->currency,
            'rate' => rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.'),
            'to' => $client->currency,
        ]), $invoice);
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

        $meta = $result->meta;
        // The saved card or PayPal account is kept on its own row, not on the payment.
        unset($meta['saved_method']);
        $transaction = $this->record($invoice, $result->amount, $gateway, $result->reference, $result->fee, $meta);

        rescue(fn () => app(SavedMethods::class)->rememberFromPayment($result, $gateway));

        return $transaction;
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
