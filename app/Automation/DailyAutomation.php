<?php

namespace App\Automation;

use App\Billing\InvoicePaidHandler;
use App\Billing\RenewalGenerator;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Invoice;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The daily billing run: renewal invoices, overdue reminders, suspensions and terminations.
 */
class DailyAutomation
{
    public function __construct(
        private RenewalGenerator $renewals,
        private Provisioner $provisioner,
        private TemplateMailer $mailer,
    ) {}

    /**
     * @return array{invoices: int, reminders: int, suspended: int, terminated: int, failed: int}
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
        ];

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
