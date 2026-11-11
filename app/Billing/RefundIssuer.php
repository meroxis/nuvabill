<?php

namespace App\Billing;

use App\Contracts\PaymentGateway;
use App\Contracts\SendsRefundsOnce;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Gateways\PaymentReversal;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\RefundUnclear;
use App\Mail\TemplateMailer;
use App\Models\ActivityLog;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

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
     * Refund what no credit note took back yet, with a credit note, and mark the invoice Refunded.
     * Kept for add-ons written before 0.6.7; new code uses CreditNotes::issue().
     *
     * @throws RuntimeException When a gateway refuses a refund, or another refund of this invoice is running.
     */
    public function refund(Invoice $invoice, bool $throughGateway): Invoice
    {
        app(CreditNotes::class)->issue($invoice, $invoice->creditableAmount(), CreditNote::METHOD_REFUND, __('Refund'), null, $throughGateway);

        return $invoice->fresh();
    }

    /**
     * Send back part or all of what was paid on the invoice: each payment gets a matching refund
     * transaction (a negative amount), oldest payment first. Credit notes call this; the invoice
     * status is theirs to change.
     *
     * Payments already refunded are skipped, so running this again after a gateway error is safe.
     * A refund whose answer was lost is saved on its payment and sent again as it was (same key and
     * amount) by gateways that keep refund keys, so it is not sent back twice.
     *
     * @param  bool  $throughGateway  Send the money back through each payment's gateway where it supports refunds.
     *                                Other payments are only recorded as refunded.
     * @return int The amount refunded, in minor units.
     *
     * @throws RuntimeException When a gateway refuses a refund, or another refund of this invoice is running.
     */
    public function refundAmount(Invoice $invoice, int $amount, bool $throughGateway): int
    {
        // Each payment can take two gateway calls of up to 30 seconds (an unclear try sent again, then the rest).
        $lock = Cache::lock("invoice-refund-{$invoice->id}", 300);

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
                    $sent = $this->refundPayment($payment, $part, $throughGateway, $payment->amount - $alreadyRefunded);
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
     * Whether a refund of this payment got no answer from the gateway and is not settled yet.
     */
    public function hasUnclearRefund(Transaction $payment): bool
    {
        return $this->pendingRefund($payment) !== null;
    }

    /**
     * Record money a gateway says went back without this site sending it: a refund made at the
     * payment service, or a dispute. A refund line is written for the payment, once, and staff are
     * told. The invoice, its services and the wallet stay as they are; a commission still on hold
     * for the invoice is cancelled when nothing of the invoice is kept. Safe to call twice for the
     * same notice, and for a refund this site sent itself.
     *
     * @return Transaction|null The line that was written, or null when there was nothing new to record.
     */
    public function recordReversal(PaymentReversal $reversal, string $gateway): ?Transaction
    {
        $name = rescue(fn (): ?string => $this->extensions->find($gateway)?->name, null, false) ?? $gateway;

        try {
            // The line and its log entry are saved together, so staff always see what was recorded.
            $line = DB::transaction(function () use ($reversal, $gateway, $name): ?Transaction {
                $line = $this->storeReversal($reversal, $gateway);

                if ($line !== null) {
                    $this->logReversal($line, $reversal, $name);
                }

                return $line;
            });
        } catch (UniqueConstraintViolationException) {
            // The same notice arrived twice at once (the reference is unique); the first one counts.
            return null;
        }

        $invoice = $line?->invoice()->first();

        // Money that came back after a dispute is only logged.
        if ($invoice !== null && $reversal->kind !== PaymentReversal::CHARGEBACK_UNDONE) {
            rescue(fn () => app(Affiliates::class)->onPaymentReversed($invoice));
            rescue(fn () => $this->emailStaff($invoice, $reversal, $name, money(abs($line->amount), $line->currency)));
        }

        return $line;
    }

    /**
     * Write the refund line for a reversal. Call it inside a database transaction: the payment's
     * row is locked until it ends, so two notices about one payment are handled in turn.
     */
    private function storeReversal(PaymentReversal $reversal, string $gateway): ?Transaction
    {
        // Only a payment recorded here with exactly this ID counts (the database may compare
        // without letter case), so notices about other payments on the same account change nothing.
        $payment = Transaction::query()
            ->where('gateway', $gateway)
            ->where('reference', $reversal->paymentReference)
            ->where('type', 'payment')
            ->lockForUpdate()
            ->get()
            ->first(fn (Transaction $payment): bool => $payment->reference === $reversal->paymentReference);

        if ($payment === null || $reversal->amount <= 0 || $reversal->reference === '') {
            return null;
        }

        if (strtoupper($reversal->currency) !== $payment->currency) {
            Log::warning("Gateway {$gateway} reported a {$reversal->kind} in {$reversal->currency} for payment {$payment->id} in {$payment->currency}; not recorded.");

            return null;
        }

        $undone = $reversal->kind === PaymentReversal::CHARGEBACK_UNDONE;
        $reference = $undone ? $reversal->reference.'-undone' : $reversal->reference;

        // Recorded before: by an earlier notice, or by this site when it sent the refund itself.
        if (Transaction::query()->where('gateway', $gateway)->where('reference', $reference)->exists()) {
            return null;
        }

        $lines = Transaction::query()
            ->where('client_id', $payment->client_id)
            ->where('gateway', $gateway)
            ->where('type', 'refund')
            ->get()
            ->filter(fn (Transaction $line): bool => ($line->meta['refund_of'] ?? null) === $payment->id);
        $disputes = $lines->filter(fn (Transaction $line): bool => in_array($line->meta['kind'] ?? null, [PaymentReversal::CHARGEBACK, PaymentReversal::CHARGEBACK_UNDONE], true));
        // What has not gone back yet: a payment never goes back more than once.
        $left = $payment->amount + (int) $lines->sum('amount');

        $amount = match ($reversal->kind) {
            PaymentReversal::REFUND => min($this->refundNotRecorded($reversal, $payment, -(int) $lines->diff($disputes)->sum('amount')), $left),
            PaymentReversal::CHARGEBACK => min($reversal->amount, $left),
            // Only what the recorded dispute took comes back.
            PaymentReversal::CHARGEBACK_UNDONE => min($reversal->amount, -(int) $disputes->whereStrict('reference', $reversal->reference)->sum('amount')),
            default => 0,
        };

        if ($amount <= 0) {
            return null;
        }

        return Transaction::create([
            'client_id' => $payment->client_id,
            'invoice_id' => $payment->invoice_id,
            'gateway' => $gateway,
            'reference' => $reference,
            'type' => 'refund',
            'amount' => $undone ? $amount : -$amount,
            'currency' => $payment->currency,
            'meta' => ['refund_of' => $payment->id, 'through_gateway' => true, 'reported_by_gateway' => true, 'kind' => $reversal->kind],
            'paid_at' => now(),
        ]);
    }

    /**
     * How much of a refund the gateway reports is not recorded here yet, in minor units. With the
     * gateway's total, it is the total less every refund of the payment already recorded (sent from
     * here, or written down by staff) and less a refund this site is sending right now. Without
     * the total, a refund of the same amount this site is sending right now is that refund.
     *
     * @param  int  $recorded  Refunds of the payment recorded so far, without disputes.
     */
    private function refundNotRecorded(PaymentReversal $reversal, Transaction $payment, int $recorded): int
    {
        $sending = $this->pendingRefund($payment)['amount'] ?? 0;

        if ($reversal->refundedSoFar !== null) {
            return min($reversal->amount, $reversal->refundedSoFar - $recorded - $sending);
        }

        return $sending === $reversal->amount ? 0 : $reversal->amount;
    }

    /**
     * Write a recorded reversal to the activity log, on its invoice. The dashboard lists the
     * invoice for staff until a credit note covers the money.
     *
     * @param  string  $gateway  The gateway's name for staff.
     */
    private function logReversal(Transaction $line, PaymentReversal $reversal, string $gateway): void
    {
        $invoice = $line->invoice()->first();
        $amount = money(abs($line->amount), $line->currency);
        $number = $invoice?->displayNumber() ?? '—';

        Activity::log(ActivityLog::PAYMENT_REVERSED, match ($reversal->kind) {
            PaymentReversal::REFUND => "{$gateway} says {$amount} of payment {$reversal->paymentReference} on invoice {$number} was refunded there. It is recorded here. Make a credit note (send it back) to finish it; no money is sent again.",
            PaymentReversal::CHARGEBACK => "{$gateway} says payment {$reversal->paymentReference} on invoice {$number} was disputed and {$amount} was taken back. It is recorded here. Check the client's services and wallet.",
            default => "{$gateway} says the dispute of payment {$reversal->paymentReference} on invoice {$number} ended well and {$amount} came back. It is recorded here.",
        }, $invoice ?? $line);
    }

    /**
     * Tell the company address about a refund made at the payment service, or a dispute. The text
     * holds no words a client chose.
     */
    private function emailStaff(Invoice $invoice, PaymentReversal $reversal, string $gateway, string $amount): void
    {
        $email = (string) setting('company.email');

        if ($email === '') {
            return;
        }

        Locales::in(Locales::default(), function () use ($invoice, $reversal, $gateway, $amount, $email): void {
            $replace = ['gateway' => $gateway, 'amount' => $amount, 'number' => $invoice->displayNumber(), 'url' => route('admin.invoices.show', $invoice)];
            [$subject, $body] = $reversal->kind === PaymentReversal::CHARGEBACK
                ? [
                    __('A payment for invoice :number was disputed at :gateway', $replace),
                    __(":gateway says the client disputed a payment for invoice :number, and :amount was taken back. It is now recorded on the invoice. Nothing else changed: the services and the wallet are as they were, so check them.\n\nIf the money stays gone, open the invoice and make a credit note for this amount with \"Send it back to the client\". No money is sent again.\n\n:url", $replace),
                ]
                : [
                    __('A payment for invoice :number was refunded at :gateway', $replace),
                    __(":gateway says :amount of a payment for invoice :number was sent back to the client there. The refund is now recorded on the invoice. Nothing else changed: the services and the wallet are as they were.\n\nTo finish it, open the invoice and make a credit note for this amount with \"Send it back to the client\". No money is sent again.\n\n:url", $replace),
                ];

            app(TemplateMailer::class)->sendText($email, (string) setting('company.name'), $subject, $body);
        });
    }

    /**
     * @param  int  $available  What is left to refund of this payment, in minor units.
     * @return int The amount refunded, in minor units.
     */
    private function refundPayment(Transaction $payment, int $amount, bool $throughGateway, int $available): int
    {
        if ($throughGateway && $payment->gateway === Wallet::GATEWAY) {
            return $this->refundToWallet($payment, $amount);
        }

        $gateway = $throughGateway ? $this->refundingGateway($payment) : null;

        if ($gateway instanceof SendsRefundsOnce) {
            return $this->refundOnce($payment, $gateway, $amount, $available);
        }

        // Only a gateway that keeps refund keys can send an unclear refund again. Staff who record
        // the refund without the gateway have checked it there, so that settles it.
        if ($throughGateway && $this->pendingRefund($payment->refresh()) !== null) {
            throw new RuntimeException($this->checkAtGatewayMessage());
        }

        $result = $gateway?->refund($payment, $amount);
        $refunded = $result?->amount ?? $amount;

        DB::transaction(function () use ($payment, $refunded, $result): void {
            $this->recordRefund($payment, $refunded, $result);
            $this->savePendingRefund($payment, null);
        });

        return $refunded;
    }

    /**
     * Send a refund through a gateway that keeps refund keys. The try is saved on the payment before
     * it is sent. When its answer is lost, that same try (same key, same amount) is sent again
     * first, whatever amount is asked for now, so the gateway sends the money back only once.
     * Stripe keeps keys for about a day and PayPal for a few hours (refundKeyHours()); an older
     * unclear try is not sent again, and staff check it at the gateway.
     *
     * @return int The amount refunded, in minor units.
     */
    private function refundOnce(Transaction $payment, PaymentGateway&SendsRefundsOnce $gateway, int $amount, int $available): int
    {
        $payment->refresh();
        $refunded = 0;
        $pending = $this->pendingRefund($payment);

        if ($pending !== null) {
            $since = rescue(fn (): CarbonImmutable => CarbonImmutable::parse($pending['since']), null, false);

            if ($since === null || $since->lt(now()->subHours(max(0, $gateway->refundKeyHours()))) || $pending['amount'] > $available) {
                throw new RuntimeException($this->checkAtGatewayMessage());
            }

            // Sending the open try again would refund more than asked for now, and the credit note
            // would then show less than the client got back.
            if ($pending['amount'] > $amount) {
                throw new RuntimeException(__('An earlier refund of :amount got no answer from the payment service. Refund at least :amount, so that same refund is sent again first.', [
                    'amount' => money($pending['amount'], $payment->currency),
                ]));
            }

            $refunded = $this->sendRefund($payment, $gateway, $pending, replay: true);
            $amount -= $refunded;
            $available -= $refunded;
        }

        $amount = min($amount, $available);

        if ($amount > 0) {
            // The random part gives a try that was refused its own new key next time.
            $attempt = [
                'key' => 'payment-'.$payment->id.'-refund-'.($this->refundCount($payment) + 1).'-'.$amount.'-'.Str::lower(Str::random(10)),
                'amount' => $amount,
                'gateway' => (string) $payment->gateway,
                'since' => now()->toIso8601String(),
            ];
            $this->savePendingRefund($payment, $attempt);
            $refunded += $this->sendRefund($payment, $gateway, $attempt);
        }

        return $refunded;
    }

    /**
     * @param  array{key: string, amount: int, gateway: string, since: string}  $attempt
     * @param  bool  $replay  Whether this sends again a try whose answer was lost.
     * @return int The amount refunded, in minor units.
     */
    private function sendRefund(Transaction $payment, PaymentGateway&SendsRefundsOnce $gateway, array $attempt, bool $replay = false): int
    {
        try {
            $result = $gateway->refundOnce($payment, $attempt['amount'], $attempt['key']);
        } catch (RefundUnclear|ConnectionException $exception) {
            // The try stays saved, so the next refund sends it again instead of a new one.
            report($exception);

            throw new RefundUnclear(__('The payment service did not answer, so the refund may have gone through. You can try again: the same refund is sent again, so the client is not refunded twice.'), previous: $exception);
        } catch (Throwable $exception) {
            // A clear refusal of a new try: no money went back, so the next try is a new one. A try sent
            // again stays saved: the error may come before the gateway saw it (no access token, for
            // example), and the first send may still have gone through. Staff settle it at the gateway.
            if (! $replay) {
                $this->savePendingRefund($payment, null);
            }

            throw $exception;
        }

        DB::transaction(function () use ($payment, $result, $attempt): void {
            $this->recordRefund($payment, $result->amount, $result, $attempt['key']);
            $this->savePendingRefund($payment, null);
        });

        return $result->amount;
    }

    private function recordRefund(Transaction $payment, int $amount, ?RefundResult $result, ?string $attemptKey = null): Transaction
    {
        // The gateway's own notice of this refund may have arrived first (see recordReversal):
        // that line is the refund, so it is not written again.
        $known = filled($result?->reference)
            ? Transaction::query()->where('gateway', $payment->gateway)->where('reference', $result->reference)->where('type', 'refund')->first()
            : null;

        if ($known !== null) {
            return $known;
        }

        return Transaction::create([
            'client_id' => $payment->client_id,
            'invoice_id' => $payment->invoice_id,
            'gateway' => $payment->gateway,
            'reference' => $result?->reference,
            'type' => 'refund',
            'amount' => -$amount,
            'currency' => $payment->currency,
            'meta' => ['refund_of' => $payment->id, 'through_gateway' => $result !== null]
                + ($attemptKey === null ? [] : ['refund_attempt' => $attemptKey])
                + ($result->meta ?? []),
            'paid_at' => now(),
        ]);
    }

    /**
     * The refund of this payment whose answer was lost, if there is one.
     *
     * @return array{key: string, amount: int, gateway: string, since: string}|null
     */
    private function pendingRefund(Transaction $payment): ?array
    {
        $pending = $payment->meta['refund_pending'] ?? null;

        if (! is_array($pending) || ! is_string($pending['key'] ?? null) || $pending['key'] === '' || ! is_int($pending['amount'] ?? null) || $pending['amount'] <= 0) {
            return null;
        }

        return [
            'key' => $pending['key'],
            'amount' => $pending['amount'],
            'gateway' => (string) ($pending['gateway'] ?? ''),
            'since' => (string) ($pending['since'] ?? ''),
        ];
    }

    /**
     * @param  array{key: string, amount: int, gateway: string, since: string}|null  $attempt  Null clears it.
     */
    private function savePendingRefund(Transaction $payment, ?array $attempt): void
    {
        $meta = (array) ($payment->meta ?? []);

        if ($attempt === null && ! array_key_exists('refund_pending', $meta)) {
            return;
        }

        if ($attempt === null) {
            unset($meta['refund_pending']);
        } else {
            $meta['refund_pending'] = $attempt;
        }

        $payment->forceFill(['meta' => $meta])->save();
    }

    private function refundCount(Transaction $payment): int
    {
        return Transaction::query()
            ->where('invoice_id', $payment->invoice_id)
            ->where('type', 'refund')
            ->get(['meta'])
            ->filter(fn (Transaction $refund): bool => ($refund->meta['refund_of'] ?? null) === $payment->id)
            ->count();
    }

    private function checkAtGatewayMessage(): string
    {
        return __('An earlier refund of this payment got no answer from the payment service. Check in the payment service whether it went through, then record the refund here without sending it through the gateway.');
    }

    /**
     * Money paid from the wallet goes back into it. The wallet credit and its refund record are
     * saved together, so a retry after an error cannot credit the wallet twice.
     */
    private function refundToWallet(Transaction $payment, int $amount): int
    {
        $payment->loadMissing('invoice.client');

        DB::transaction(function () use ($payment, $amount): void {
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
        });

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
