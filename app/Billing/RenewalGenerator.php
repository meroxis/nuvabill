<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Creates renewal invoices for services that are due soon. Safe to run many times a day:
 * a period that already has an invoice is never invoiced again.
 */
class RenewalGenerator
{
    public function __construct(
        private InvoiceManager $invoices,
        private TemplateMailer $mailer,
    ) {}

    /**
     * @return int The number of invoices created.
     */
    public function generate(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? today());
        $cutoff = $today->addDays((int) setting('billing.renewal_days_before'));

        $services = Service::query()
            ->with('product', 'client')
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereNotIn('billing_cycle', [BillingCycle::OneTime, BillingCycle::Free])
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $cutoff)
            ->where('recurring_amount', '>', 0)
            ->get()
            ->reject(fn (Service $service): bool => $this->alreadyInvoiced($service));

        $created = 0;

        $services
            ->groupBy(fn (Service $service): string => $service->client_id.'|'.$service->next_due_date->toDateString())
            ->each(function (Collection $group) use (&$created): void {
                /** @var Service $first */
                $first = $group->first();

                $items = $group->map(fn (Service $service): array => LineItems::servicePeriod(
                    $service,
                    $service->next_due_date,
                    $service->recurring_amount,
                ))->values()->all();

                $invoice = $this->invoices->create($first->client, $items, dueAt: $first->next_due_date, currency: $first->currency);

                Activity::log('invoice.renewal', "Renewal invoice {$invoice->number} created", $invoice, $first->client);
                $this->mailer->send('invoice.created', $first->client, TemplateMailer::invoiceContext($invoice));
                $created++;
            });

        return $created;
    }

    private function alreadyInvoiced(Service $service): bool
    {
        return InvoiceItem::query()
            ->where('service_id', $service->id)
            ->where('type', InvoiceItem::TYPE_SERVICE)
            ->whereDate('period_start', $service->next_due_date)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->exists();
    }
}
