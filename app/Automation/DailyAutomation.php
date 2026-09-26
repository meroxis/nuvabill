<?php

namespace App\Automation;

use App\Billing\InvoicePaidHandler;
use App\Billing\RenewalGenerator;
use App\Domains\DomainProvisioner;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The daily billing run: renewal invoices, overdue reminders, suspensions, terminations and domain upkeep.
 */
class DailyAutomation
{
    public function __construct(
        private RenewalGenerator $renewals,
        private Provisioner $provisioner,
        private DomainProvisioner $domains,
        private TemplateMailer $mailer,
    ) {}

    /**
     * How many domains are checked with their registrar per run, so the run stays short.
     */
    private const DOMAIN_SYNC_LIMIT = 25;

    /**
     * @return array{invoices: int, reminders: int, suspended: int, terminated: int, failed: int, domains_expired: int}
     */
    public function run(?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? today())->startOfDay();

        $summary = [
            'invoices' => $this->renewals->generate($today),
            'reminders' => $this->sendReminders($today),
            'suspended' => 0,
            'terminated' => 0,
            'failed' => 0,
            'domains_expired' => 0,
        ];

        $this->syncDomains();
        $summary['domains_expired'] = $this->expireDomains($today);
        $this->sendExpiryNotices($today);

        $suspendDays = (int) setting('automation.suspend_days');

        if ($suspendDays > 0) {
            foreach ($this->servicesOverdueSince($today->subDays($suspendDays), ServiceStatus::Active) as $service) {
                $this->provisioner->suspend($service, InvoicePaidHandler::OVERDUE_REASON)->success
                    ? $summary['suspended']++
                    : $summary['failed']++;
            }
        }

        $terminateDays = (int) setting('automation.terminate_days');

        if ($terminateDays > 0) {
            foreach ($this->servicesOverdueSince($today->subDays($terminateDays), ServiceStatus::Suspended) as $service) {
                $this->provisioner->terminate($service)->success
                    ? $summary['terminated']++
                    : $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * Check transfers in progress, and the expiry dates of domains not checked for a week.
     */
    private function syncDomains(): void
    {
        Domain::query()
            ->whereNotNull('registrar')
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
            ->each(function (Domain $domain) use (&$expired): void {
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

        Domain::query()
            ->with('client')
            ->where('status', DomainStatus::Active)
            ->where('auto_renew', false)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', $today)
            ->whereDate('expires_at', '<=', $today->addDays($days->max()))
            ->where(fn (Builder $query) => $query->whereNull('expiry_notice_sent_at')->orWhere('expiry_notice_sent_at', '<', now()->subDays(5)))
            ->each(function (Domain $domain) use ($today, $days): void {
                $daysLeft = (int) $today->diffInDays($domain->expires_at);

                if (! $days->contains(fn (int $day): bool => $daysLeft <= $day && $daysLeft > $day - 5)) {
                    return;
                }

                $this->mailer->send('domain.expiring', $domain->client, DomainProvisioner::context($domain) + ['days_left' => $daysLeft]);
                $domain->forceFill(['expiry_notice_sent_at' => now()])->save();
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
            ->each(function (Invoice $invoice) use ($today, $days, &$sent): void {
                $daysOverdue = (int) $invoice->due_at->diffInDays($today);
                $stepsReached = $days->filter(fn (int $day): bool => $day <= $daysOverdue)->count();

                if ($stepsReached === 0 || $invoice->reminder_count >= $stepsReached) {
                    return;
                }

                $this->mailer->send('invoice.reminder', $invoice->client, TemplateMailer::invoiceContext($invoice) + [
                    'days_overdue' => $daysOverdue,
                ]);

                $invoice->forceFill(['reminder_count' => $stepsReached, 'last_reminder_at' => now()])->save();
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
