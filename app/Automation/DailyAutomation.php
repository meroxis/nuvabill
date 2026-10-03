<?php

namespace App\Automation;

use App\Billing\Affiliates;
use App\Billing\AutoPay;
use App\Billing\InvoicePaidHandler;
use App\Billing\OrderCanceller;
use App\Billing\PlanChanges;
use App\Billing\RenewalGenerator;
use App\Domains\DomainProvisioner;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplateMailer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The daily billing run: renewal invoices, overdue reminders, suspensions, terminations and domain upkeep.
 *
 * Running it twice never bills anyone twice: only one run works at a time, renewal periods are
 * unique in the database, and every reminder or notice is claimed before it is sent.
 */
class DailyAutomation
{
    public function __construct(
        private RenewalGenerator $renewals,
        private Provisioner $provisioner,
        private DomainProvisioner $domains,
        private TemplateMailer $mailer,
        private Affiliates $affiliates,
        private AutoPay $autoPay,
        private ExtensionManager $extensions,
    ) {}

    /**
     * How many domains are checked with their registrar per run, so the run stays short.
     */
    private const DOMAIN_SYNC_LIMIT = 25;

    /**
     * The lock that keeps two runs from working at the same time, for example the nightly cron job
     * and a second cron entry, or a run that is still busy when the next one starts.
     */
    public const LOCK = 'nuvabill:daily-automation';

    /**
     * Run everything once. Returns null when another run is busy: it does the work, so nothing is
     * done twice. Each step is also safe on its own when repeated (see the steps below).
     *
     * @return array{invoices: int, charged: int, charge_failed: int, reminders: int, suspended: int, terminated: int, failed: int, domains_expired: int, commissions: int, orders_cancelled: int}|null
     */
    public function run(?CarbonInterface $today = null): ?array
    {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->runSteps(CarbonImmutable::instance($today ?? today())->startOfDay());
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{invoices: int, charged: int, charge_failed: int, reminders: int, suspended: int, terminated: int, failed: int, domains_expired: int, commissions: int, orders_cancelled: int}
     */
    private function runSteps(CarbonImmutable $today): array
    {
        // Downgrades planned for today's renewals first, so the server matches the new plan.
        app(PlanChanges::class)->applyScheduled($today);

        $invoices = $this->renewals->generate($today);
        // Upgrades kept open on an earlier night stop once their payment is no longer going through,
        // before suspensions, so a stale upgrade invoice never suspends a renewed service.
        app(PlanChanges::class)->settlePassed();
        // Saved cards are charged before reminders and suspensions, so a paid renewal is never suspended.
        $autoPay = $this->autoPay->run($today);
        // New orders nobody paid for give back their stock and server room, after their card had its chance.
        $ordersCancelled = app(OrderCanceller::class)->cancelUnpaid($today);

        $summary = [
            'invoices' => $invoices,
            'charged' => $autoPay['charged'],
            'charge_failed' => $autoPay['failed'],
            'reminders' => $this->sendReminders($today),
            'suspended' => 0,
            'terminated' => 0,
            'failed' => 0,
            'domains_expired' => 0,
            'commissions' => $this->affiliates->release($today),
            'orders_cancelled' => $ordersCancelled,
        ];

        $this->syncDomains();
        $summary['domains_expired'] = $this->expireDomains($today);
        $this->sendExpiryNotices($today);

        $suspendDays = (int) setting('automation.suspend_days');

        if ($suspendDays > 0) {
            foreach ($this->servicesOverdueSince($today->subDays($suspendDays), ServiceStatus::Active) as $service) {
                $this->safely(fn (): bool => $this->provisioner->suspend($service, InvoicePaidHandler::OVERDUE_REASON)->success)
                    ? $summary['suspended']++
                    : $summary['failed']++;
            }
        }

        $terminateDays = (int) setting('automation.terminate_days');

        if ($terminateDays > 0) {
            foreach ($this->servicesOverdueSince($today->subDays($terminateDays), ServiceStatus::Suspended) as $service) {
                $this->safely(fn (): bool => $this->provisioner->terminate($service)->success)
                    ? $summary['terminated']++
                    : $summary['failed']++;
            }
        }

        // Older suspensions the server module should finish, for example Proxmox VPSs that still start on boot.
        $this->provisioner->recheckSuspensions();

        return $summary;
    }

    /**
     * Run one service's step. An error is reported and counted as failed, so one service or
     * server that breaks does not stop the suspensions and terminations of all the others.
     *
     * @param  Closure(): bool  $step
     */
    private function safely(Closure $step): bool
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Check transfers in progress, and the expiry dates of domains not checked for a week. Only
     * domains at a registrar that is switched on and set up: the others cannot be checked, and
     * would take every place in the run night after night.
     */
    private function syncDomains(): void
    {
        $registrars = $this->extensions->activeRegistrars()->keys()->all();

        if ($registrars === []) {
            return;
        }

        Domain::query()
            ->whereIn('registrar', $registrars)
            ->where(fn (Builder $query) => $query
                ->where('status', DomainStatus::PendingTransfer)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', DomainStatus::Active)
                    ->where(fn (Builder $query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subWeek()))))
            ->orderBy('last_synced_at')
            ->limit(self::DOMAIN_SYNC_LIMIT)
            ->get()
            ->each(fn (Domain $domain) => $this->domains->sync($domain));
    }

    private function expireDomains(CarbonImmutable $today): int
    {
        $expired = 0;

        Domain::query()
            ->where('status', DomainStatus::Active)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today)
            ->eachById(function (Domain $domain) use (&$expired): void {
                $domain->update(['status' => DomainStatus::Expired]);
                Activity::log('domain.expired', "Domain {$domain->name} expired", $domain);
                $expired++;
            });

        return $expired;
    }

    /**
     * Warn clients about domains that will not renew on their own, a set number of days before they expire.
     */
    private function sendExpiryNotices(CarbonImmutable $today): void
    {
        $days = collect((array) setting('domains.expiry_notice_days'))->map(fn (mixed $day): int => (int) $day)->filter(fn (int $day): bool => $day > 0);

        if ($days->isEmpty()) {
            return;
        }

        // At most one notice a day for each domain.
        $notToday = fn (Builder $query) => $query->whereNull('expiry_notice_sent_at')->orWhere('expiry_notice_sent_at', '<', $today);

        Domain::query()
            ->with('client')
            ->where('status', DomainStatus::Active)
            ->where('auto_renew', false)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $today)
            ->whereDate('expires_at', '<=', $today->addDays($days->max()))
            ->where($notToday)
            ->eachById(function (Domain $domain) use ($today, $days, $notToday): void {
                $daysLeft = (int) $today->diffInDays($domain->expires_at);
                // The nearest warning day reached, for example 3 of "7, 3, 1" with 2 days left.
                $warning = $days->filter(fn (int $day): bool => $daysLeft <= $day)->min();

                // Not reached yet, or more than 5 days late (a run was missed): no stale notice.
                if ($warning === null || $daysLeft <= $warning - 5) {
                    return;
                }

                // The last notice was sent with this many days left, or fewer: this warning went out already.
                $leftAtLastNotice = $domain->expiry_notice_sent_at === null
                    ? PHP_INT_MAX
                    : (int) $domain->expiry_notice_sent_at->copy()->startOfDay()->diffInDays($domain->expires_at, false);

                if ($leftAtLastNotice <= $warning) {
                    return;
                }

                // Claim the notice in one update before sending, so it goes out once even if two runs meet.
                $claimed = Domain::query()->whereKey($domain->id)->where($notToday)->update(['expiry_notice_sent_at' => now()]);

                if ($claimed === 1) {
                    $this->mailer->send('domain.expiring', $domain->client, DomainProvisioner::context($domain) + ['days_left' => $daysLeft]);
                }
            });
    }

    private function sendReminders(CarbonImmutable $today): int
    {
        $days = collect((array) setting('automation.reminder_days'))
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day > 0)
            ->sort()
            ->values();

        if ($days->isEmpty()) {
            return 0;
        }

        $sent = 0;

        Invoice::query()
            ->with('client')
            ->where('status', InvoiceStatus::Unpaid)
            ->whereDate('due_at', '<', $today)
            // An invoice with nothing left to pay is never chased.
            ->whereColumn('amount_paid', '<', 'total')
            // While a saved card is still to be tried again, the failed-payment email already told the client.
            ->where(fn (Builder $query) => $query->whereNull('autopay_retry_at')->orWhere('autopay_retry_at', '<=', now()))
            ->each(function (Invoice $invoice) use ($today, $days, &$sent): void {
                $daysOverdue = (int) $invoice->due_at->diffInDays($today);
                $stepsReached = $days->filter(fn (int $day): bool => $day <= $daysOverdue)->count();

                if ($stepsReached === 0 || $invoice->reminder_count >= $stepsReached) {
                    return;
                }

                // Claim this reminder step in one update before sending, so it is sent once even if two runs meet.
                $claimed = Invoice::query()->whereKey($invoice->id)
                    ->where('status', InvoiceStatus::Unpaid)
                    ->where('reminder_count', '<', $stepsReached)
                    ->update(['reminder_count' => $stepsReached, 'last_reminder_at' => now()]);

                if ($claimed !== 1) {
                    return;
                }

                $this->mailer->send('invoice.reminder', $invoice->client, TemplateMailer::invoiceContext($invoice) + [
                    'days_overdue' => $daysOverdue,
                ]);

                $sent++;
            });

        return $sent;
    }

    /**
     * Services in the given status with an unpaid invoice that was due on or before the cutoff date.
     *
     * @return iterable<Service>
     */
    private function servicesOverdueSince(CarbonImmutable $cutoff, ServiceStatus $status): iterable
    {
        return Service::query()
            ->with('product', 'client', 'server')
            ->where('status', $status)
            ->when($status === ServiceStatus::Suspended, fn (Builder $query) => $query->where('suspension_reason', InvoicePaidHandler::OVERDUE_REASON))
            ->whereHas('invoiceItems.invoice', fn (Builder $query) => $query
                ->where('status', InvoiceStatus::Unpaid)
                ->whereDate('due_at', '<=', $cutoff))
            ->get();
    }
}
