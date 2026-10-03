<?php

namespace App\Billing;

use App\Contracts\ChecksSavedCharges;
use App\Enums\InvoiceStatus;
use App\Extensions\Gateways\ChargeResult;
use App\Mail\TemplateMailer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Automatic payments: renewal invoices are paid from the wallet first, then charged to the card
 * or PayPal account the client saved. Clients get an email before a charge, and when a charge
 * fails, with a Pay button; Nuvabill tries again on the days set in Settings → Automatic payments.
 * After the last try the usual overdue reminders and suspensions take over.
 *
 * Part of the nightly run (see DailyAutomation). Each invoice is locked while it is charged, and
 * every attempt has its own key at the gateway, so a card is never charged twice for one try.
 * A try whose result is not known (still processing at the bank, or no answer) is kept on the
 * invoice and checked before anything else, so an unclear payment is never followed by a second one.
 */
class AutoPay
{
    public function __construct(
        private SavedMethods $methods,
        private PaymentRecorder $payments,
        private Wallet $wallet,
        private TemplateMailer $mailer,
    ) {}

    public function isOn(): bool
    {
        return (bool) setting('billing.autopay');
    }

    /**
     * The saved method that will pay this invoice by itself, or null when the client pays it by hand.
     */
    public function methodFor(Invoice $invoice): ?PaymentMethod
    {
        $invoice->loadMissing('client');

        if (! $this->isOn() || $invoice->client === null || ! $invoice->client->auto_pay || ! self::isRenewal($invoice)) {
            return null;
        }

        return $this->usableMethod($invoice);
    }

    /**
     * The day the invoice is charged: its due date, a few days before it when set, or the next try.
     */
    public function chargeDate(Invoice $invoice): CarbonImmutable
    {
        if ($invoice->autopay_attempts > 0 && $invoice->autopay_retry_at !== null) {
            return CarbonImmutable::instance($invoice->autopay_retry_at)->startOfDay();
        }

        return CarbonImmutable::instance($invoice->due_at)->subDays((int) setting('billing.autopay_days_before'))->startOfDay();
    }

    /**
     * Whether a failed invoice will be tried again by itself.
     */
    public function willRetry(Invoice $invoice): bool
    {
        return $invoice->autopay_attempts > 0 && $invoice->autopay_retry_at !== null;
    }

    /**
     * The nightly work: notices before charges, the charges due today, and expiring card notices.
     *
     * @return array{charged: int, failed: int, notices: int, card_notices: int}
     */
    public function run(CarbonImmutable $today): array
    {
        $summary = ['charged' => 0, 'failed' => 0, 'pending' => 0, 'notices' => 0, 'card_notices' => 0];

        // The demo never charges anyone.
        if (! $this->isOn() || Demo::isEnabled()) {
            return $summary;
        }

        // Error messages kept on invoices for staff are in English; emails are built in each client's language.
        return Locales::inEnglish(function () use ($today, $summary): array {
            $summary['notices'] = $this->sendNotices($today);

            foreach ($this->dueInvoices($today) as $invoice) {
                $result = $this->charge($invoice);
                $summary[$result->isPaid() ? 'charged' : ($result->isPending() ? 'pending' : 'failed')]++;
            }

            $summary['card_notices'] = $this->sendCardNotices($today);

            return $summary;
        });
    }

    /**
     * Pay the invoice now: wallet credit first, then the saved method. $by names the staff member
     * who pressed "Charge now"; their attempt does not change the automatic schedule.
     */
    public function charge(Invoice $invoice, ?PaymentMethod $method = null, ?string $by = null): ChargeResult
    {
        $lock = Cache::lock('nuvabill:autopay:'.$invoice->id, 120);

        if (! $lock->get()) {
            return ChargeResult::failed(__('This invoice is being charged right now.'));
        }

        try {
            $invoice = $invoice->fresh(['client']) ?? $invoice;

            if (! $invoice->isPayable()) {
                return ChargeResult::settled();
            }

            // A payment whose result was not known is settled first, so the invoice is not charged twice.
            $pending = is_array($invoice->autopay_pending) ? $invoice->autopay_pending : null;
            $earlier = $pending === null ? null : $this->checkPending($invoice, $pending);

            if ($earlier !== null) {
                return $this->settle($invoice, PaymentMethod::query()->find($pending['method'] ?? 0), $earlier, $by, $pending);
            }

            if ($this->wallet->enabled()) {
                $this->wallet->pay($invoice);
                $invoice->refresh();

                if (! $invoice->isPayable()) {
                    return ChargeResult::settled();
                }
            }

            $method ??= $this->usableMethod($invoice);
            $gateway = $method ? $this->methods->gateway($method->gateway) : null;

            if ($method === null || $gateway === null) {
                return ChargeResult::failed(__('No saved card or PayPal account can pay this invoice.'));
            }

            // One key per try: the same try sent twice is charged once at the gateway. A gateway that
            // cannot be asked about an unclear try gets that try again, with the same key.
            $attemptKey = $pending !== null && ($pending['gateway'] ?? null) === $method->gateway && ! $gateway instanceof ChecksSavedCharges
                ? (string) $pending['key']
                : 'invoice-'.$invoice->id.'-'.$invoice->balance().'-'.($by === null ? 'try-'.$invoice->autopay_attempts : 'staff-'.now()->getTimestampMs());

            // Kept before the charge, so a payment the gateway took just before the connection broke
            // is checked before the next try.
            $attempt = ['gateway' => $method->gateway, 'method' => $method->id, 'customer' => $method->customer_reference, 'key' => $attemptKey, 'since' => now()->toIso8601String()];
            $invoice->forceFill(['autopay_pending' => $attempt])->save();

            try {
                $result = $gateway->chargeSaved($method, $invoice, $attemptKey);
            } catch (Throwable $exception) {
                report($exception);
                $result = ChargeResult::pending(__('The payment service did not answer. The payment is checked before the next try.'));
            }

            return $this->settle($invoice, $method, $result, $by, $attempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * Record what a charge or a check of an unclear charge found.
     *
     * @param  array<string, mixed>  $attempt  The try: gateway, method, customer, key, since and the gateway's reference.
     */
    private function settle(Invoice $invoice, ?PaymentMethod $method, ChargeResult $result, ?string $by, array $attempt): ChargeResult
    {
        $label = $method?->label() ?? __('saved payment method');

        if ($result->isPaid() && $result->payment !== null) {
            $this->payments->recordGatewayResult($result->payment, (string) $attempt['gateway']);
            $method?->forceFill(['last_used_at' => now()])->save();
            $invoice->forceFill(['autopay_error' => null, 'autopay_retry_at' => null, 'autopay_pending' => null])->save();
            Activity::log('invoice.autopay_paid', ($by ? "{$by} charged" : 'Charged')." {$label} for invoice {$invoice->displayNumber()}", $invoice, $invoice->client);

            return $result;
        }

        if ($result->isPending()) {
            // Checked again by the next nightly run; no failure email, as nothing failed yet.
            $invoice->forceFill([
                'autopay_pending' => array_filter(['reference' => $result->reference ?? $attempt['reference'] ?? null] + $attempt),
                'autopay_error' => Str::limit($result->message, 240),
                'autopay_retry_at' => Carbon::tomorrow(),
            ])->save();
            Activity::log('invoice.autopay_pending', "Payment with {$label} for invoice {$invoice->displayNumber()} is not finished: {$result->message}", $invoice, $invoice->client);

            return $result;
        }

        $invoice->forceFill(['autopay_pending' => null])->save();
        $this->failed($invoice, $label, $result, $by);

        return $result;
    }

    /**
     * What became of a try whose result was not known, or null when the gateway has no payment for
     * it and the invoice can be charged.
     *
     * @param  array<string, mixed>  $pending
     */
    private function checkPending(Invoice $invoice, array $pending): ?ChargeResult
    {
        $gateway = $this->methods->gateway((string) ($pending['gateway'] ?? ''));
        $reference = isset($pending['reference']) ? (string) $pending['reference'] : null;

        if (! $gateway instanceof ChecksSavedCharges) {
            // Still processing, and the gateway cannot be asked: its webhook settles the invoice.
            return $reference !== null ? ChargeResult::pending(__('The payment is still being processed.'), $reference) : null;
        }

        try {
            $result = $gateway->checkSavedCharge($invoice, (string) ($pending['key'] ?? ''), $reference, isset($pending['customer']) ? (string) $pending['customer'] : null);
        } catch (Throwable $exception) {
            report($exception);

            return ChargeResult::pending(__('The payment service did not answer. The payment is checked before the next try.'), $reference);
        }

        // A payment sent a few minutes ago may not be listed at the gateway yet.
        if ($result === null && Carbon::parse((string) ($pending['since'] ?? 'now'))->gt(now()->subMinutes(10))) {
            return ChargeResult::pending(__('The last try is still being checked. Try again in a few minutes.'));
        }

        return $result;
    }

    /**
     * Renewal invoices are the ones Nuvabill charges by itself; staff can charge any invoice.
     */
    public static function isRenewal(Invoice $invoice): bool
    {
        return $invoice->items()->whereNotNull('billing_key')->exists();
    }

    /**
     * @return array<int, int> Days after the first try, in order, for example [3, 7].
     */
    public static function retryDays(): array
    {
        return collect((array) setting('billing.autopay_retry_days'))
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day > 0 && $day <= 60)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function usableMethod(Invoice $invoice): ?PaymentMethod
    {
        $method = $invoice->client?->defaultPaymentMethod();
        $gateway = $method ? $this->methods->gateways($invoice->currency)->get($method->gateway) : null;

        return $method !== null && $gateway !== null && ! $method->isExpired() ? $method : null;
    }

    /**
     * Invoices the nightly run charges on this day.
     *
     * @return Collection<int, Invoice>
     */
    public function dueInvoices(CarbonImmutable $today): Collection
    {
        return $this->candidates()
            ->whereDate('due_at', '<=', $today->addDays((int) setting('billing.autopay_days_before')))
            ->where('autopay_attempts', '<=', count(self::retryDays()))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('autopay_attempts', 0)->whereNull('autopay_retry_at'))
                ->orWhere('autopay_retry_at', '<=', $today->endOfDay()))
            ->get();
    }

    /**
     * Unpaid renewal invoices of clients who have automatic payments on and a saved method.
     *
     * @return Builder<Invoice>
     */
    private function candidates(): Builder
    {
        return Invoice::query()
            ->with('client')
            ->where('status', InvoiceStatus::Unpaid)
            ->whereHas('items', fn (Builder $query) => $query->whereNotNull('billing_key'))
            ->whereHas('client', fn (Builder $query) => $query->where('auto_pay', true)->whereHas('paymentMethods'));
    }

    private function failed(Invoice $invoice, string $label, ChargeResult $result, ?string $by): void
    {
        $message = Str::limit($result->message, 240);
        Activity::log('invoice.autopay_failed', ($by ? "{$by} could not charge" : 'Could not charge')." {$label} for invoice {$invoice->displayNumber()}: {$message}", $invoice, $invoice->client);

        if ($by !== null) {
            $invoice->forceFill(['autopay_error' => $message])->save();

            return;
        }

        $retryDays = self::retryDays();
        $attempts = $invoice->autopay_attempts + 1;
        $retryAt = null;

        // Trying again cannot help when the bank wants the client to confirm the payment.
        if ($result->status !== ChargeResult::NEEDS_CLIENT && isset($retryDays[$attempts - 1])) {
            $sinceLastTry = $retryDays[$attempts - 1] - ($attempts >= 2 ? $retryDays[$attempts - 2] : 0);
            $retryAt = Carbon::today()->addDays(max(1, $sinceLastTry));
        }

        $invoice->forceFill([
            'autopay_attempts' => $retryAt === null ? count($retryDays) + 1 : $attempts,
            'autopay_retry_at' => $retryAt,
            'autopay_error' => $message,
        ])->save();

        $this->mailer->send('invoice.autopay_failed', $invoice->client, TemplateMailer::invoiceContext($invoice) + [
            'payment_method' => ['name' => $label],
            'failure' => $message,
            'next_try' => Locales::in(Locales::forClient($invoice->client), fn (): string => $retryAt
                ? __('We will try again on :date.', ['date' => $retryAt->translatedFormat('d M Y')])
                : __('We will not try again by ourselves, so please pay the invoice with the button below.')),
            'payment_methods_url' => route('client.account.payment-methods'),
        ]);
    }

    private function sendNotices(CarbonImmutable $today): int
    {
        $days = (int) setting('billing.autopay_notice_days');

        if ($days <= 0) {
            return 0;
        }

        $before = (int) setting('billing.autopay_days_before');
        $sent = 0;

        $this->candidates()
            ->whereNull('autopay_notice_at')
            ->where('autopay_attempts', 0)
            ->whereDate('due_at', '>', $today->addDays($before))
            ->whereDate('due_at', '<=', $today->addDays($before + $days))
            ->eachById(function (Invoice $invoice) use (&$sent): void {
                $method = $this->methodFor($invoice);

                if ($method === null) {
                    return;
                }

                // Claimed before sending, so it goes out once even if two runs meet.
                if (Invoice::query()->whereKey($invoice->id)->whereNull('autopay_notice_at')->update(['autopay_notice_at' => now()]) !== 1) {
                    return;
                }

                $this->mailer->send('invoice.autopay_upcoming', $invoice->client, TemplateMailer::invoiceContext($invoice) + [
                    'payment_method' => ['name' => $method->label()],
                    'charge_date' => Locales::in(Locales::forClient($invoice->client), fn (): string => $this->chargeDate($invoice)->translatedFormat('d M Y')),
                    'payment_methods_url' => route('client.account.payment-methods'),
                ]);
                $sent++;
            });

        return $sent;
    }

    private function sendCardNotices(CarbonImmutable $today): int
    {
        if (! setting('billing.autopay_card_notice')) {
            return 0;
        }

        $sent = 0;

        PaymentMethod::query()
            ->with('client')
            ->where('type', PaymentMethod::TYPE_CARD)
            ->whereNull('expiry_notice_at')
            ->whereNotNull('expires_year')
            ->get()
            ->filter(fn (PaymentMethod $method): bool => $method->expiresOn()?->between($today, $today->addDays(30)) ?? false)
            ->each(function (PaymentMethod $method) use (&$sent): void {
                if ($method->client === null || PaymentMethod::query()->whereKey($method->id)->whereNull('expiry_notice_at')->update(['expiry_notice_at' => now()]) !== 1) {
                    return;
                }

                $this->mailer->send('payment.method_expiring', $method->client, [
                    'payment_method' => Locales::in(Locales::forClient($method->client), fn (): array => ['name' => $method->label(), 'expires' => $method->expiresOn()?->translatedFormat('F Y')]),
                    'payment_methods_url' => route('client.account.payment-methods'),
                ]);
                $sent++;
            });

        return $sent;
    }
}
