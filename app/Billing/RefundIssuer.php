<?php

namespace App\Billing;

use App\Contracts\PaymentGateway;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sends money paid on an invoice back: through the gateway when asked and the gateway can do it,
 * into the wallet for wallet payments, or only recorded. CreditNotes decides how much and why.
 */
class RefundIssuer
{
    public function __construct(
        private ExtensionManager $extensions,
        private Wallet $wallet,
    ) {}

    /**
     * Send back part or all of what was paid on the invoice: each payment gets a matching refund
     * transaction (a negative amount), oldest payment first. Credit notes call this; the invoice
     * status is theirs to change.
     *
     * Payments already refunded are skipped, so running this again after a gateway error is safe.
     *
     * @param  bool  $throughGateway  Send the money back through each payment's gateway where it supports refunds.
     *                                Other payments are only recorded as refunded.
     * @return int The amount refunded, in minor units.
     *
     * @throws RuntimeException When a gateway refuses a refund, or another refund of this invoice is running.
     */
    public function refundAmount(Invoice $invoice, int $amount, bool $throughGateway): int
    {
        $lock = Cache::lock("invoice-refund-{$invoice->id}", 120);

        if (! $lock->get()) {
            throw new RuntimeException(__('This invoice is already being refunded. Try again in a minute.'));
        }

        try {
            $invoice = $invoice->fresh(['transactions']);

            if ($invoice->status !== InvoiceStatus::Paid) {
                throw new InvalidArgumentException('Only paid invoices can be refunded.');
            }

            $refunds = $invoice->transactions->where('type', 'refund');
            $remaining = min($amount, $invoice->total + (int) $refunds->sum('amount'));
            $refunded = 0;

            foreach ($invoice->transactions->where('type', 'payment')->sortBy('id') as $payment) {
                if ($remaining <= 0) {
                    break;
                }

                $alreadyRefunded = -(int) $refunds->filter(fn (Transaction $refund): bool => ($refund->meta['refund_of'] ?? null) === $payment->id)->sum('amount');
                $part = min($payment->amount - $alreadyRefunded, $remaining);

                if ($part > 0) {
                    $sent = $this->refundPayment($payment, $part, $throughGateway);
                    $remaining -= $sent;
                    $refunded += $sent;
                }
            }
        } finally {
            $lock->release();
        }

        return $refunded;
    }

    /**
     * Whether this payment's money can be sent back through its gateway.
     */
    public function canRefundThroughGateway(Transaction $payment): bool
    {
        return $payment->gateway === Wallet::GATEWAY || $this->refundingGateway($payment) !== null;
    }

    /**
     * @return int The amount refunded, in minor units.
     */
    private function refundPayment(Transaction $payment, int $amount, bool $throughGateway): int
    {
        if ($throughGateway && $payment->gateway === Wallet::GATEWAY) {
            return $this->refundToWallet($payment, $amount);
        }

        $gateway = $throughGateway ? $this->refundingGateway($payment) : null;
        $result = $gateway?->refund($payment, $amount);
        $refunded = $result?->amount ?? $amount;

        Transaction::create([
            'client_id' => $payment->client_id,
            'invoice_id' => $payment->invoice_id,
            'gateway' => $payment->gateway,
            'reference' => $result?->reference,
            'type' => 'refund',
            'amount' => -$refunded,
            'currency' => $payment->currency,
            'meta' => ['refund_of' => $payment->id, 'through_gateway' => $result !== null] + ($result->meta ?? []),
            'paid_at' => now(),
        ]);

        return $refunded;
    }

    /**
     * Money paid from the wallet goes back into it.
     */
    private function refundToWallet(Transaction $payment, int $amount): int
    {
        $payment->loadMissing('invoice.client');
        $entry = $this->wallet->change($payment->invoice->client, $amount, __('Refund of invoice :number', ['number' => $payment->invoice->displayNumber()]), $payment->invoice);

        Transaction::create([
            'client_id' => $payment->client_id,
            'invoice_id' => $payment->invoice_id,
            'gateway' => Wallet::GATEWAY,
            'reference' => 'wallet-'.$entry->id,
            'type' => 'refund',
            'amount' => -$amount,
            'currency' => $payment->currency,
            'meta' => ['refund_of' => $payment->id, 'through_gateway' => true],
            'paid_at' => now(),
        ]);

        return $amount;
    }

    private function refundingGateway(Transaction $payment): ?PaymentGateway
    {
        if (blank($payment->reference)) {
            return null;
        }

        try {
            $gateway = $this->extensions->gateway($payment->gateway);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $gateway->supportsRefunds() && $gateway->isConfigured() ? $gateway : null;
    }
}
