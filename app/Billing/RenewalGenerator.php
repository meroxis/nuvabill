<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\TldPrice;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Creates renewal invoices for services and domains that are due soon. Safe to run many times a day:
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

        /** @var array<string, array{client: Client, currency: string, due: CarbonImmutable, items: list<array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($this->dueServices($today) as $service) {
            $this->addToGroup($groups, $service->client, $service->currency, $service->next_due_date, LineItems::servicePeriod(
                $service,
                $service->next_due_date,
                $service->recurring_amount,
            ));
        }

        foreach ($this->dueDomains($today) as $domain) {
            $this->addToGroup($groups, $domain->client, $domain->currency, $domain->next_due_date, LineItems::domainPeriod(
                $domain,
                InvoiceItem::TYPE_DOMAIN_RENEW,
                max(1, $domain->years),
                $domain->recurring_amount,
                $domain->next_due_date,
            ));
        }

        foreach ($groups as $group) {
            $invoice = $this->invoices->create($group['client'], $group['items'], dueAt: $group['due'], currency: $group['currency']);

            Activity::log('invoice.renewal', "Renewal invoice {$invoice->number} created", $invoice, $group['client']);
            $this->mailer->send('invoice.created', $group['client'], TemplateMailer::invoiceContext($invoice));
        }

        return count($groups);
    }

    /**
     * The invoice for a domain's next period, created now if it does not exist yet. Used when a
     * client or staff member wants to renew before the automatic renewal invoice.
     */
    public function invoiceDomainRenewal(Domain $domain): Invoice
    {
        $domain->loadMissing('client');
        $start = $domain->next_due_date ?? $domain->expires_at ?? CarbonImmutable::today();

        $existing = InvoiceItem::query()
            ->with('invoice')
            ->where('domain_id', $domain->id)
            ->where('type', InvoiceItem::TYPE_DOMAIN_RENEW)
            ->whereDate('period_start', $start)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing->invoice;
        }

        $years = max(1, $domain->years);
        $amount = $domain->recurring_amount ?: (int) TldPrice::forTld($domain->tld, $domain->currency)?->priceFor('renew', $years);

        $invoice = $this->invoices->create(
            $domain->client,
            [LineItems::domainPeriod($domain, InvoiceItem::TYPE_DOMAIN_RENEW, $years, $amount, $start)],
            dueAt: CarbonImmutable::today(),
            currency: $domain->currency,
        );

        Activity::log('invoice.renewal', "Renewal invoice {$invoice->number} created for {$domain->name}", $invoice, $domain->client);
        $this->mailer->send('invoice.created', $domain->client, TemplateMailer::invoiceContext($invoice));

        return $invoice;
    }

    /**
     * @return iterable<Service>
     */
    private function dueServices(CarbonImmutable $today): iterable
    {
        return Service::query()
            ->with('product', 'client')
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereNotIn('billing_cycle', [BillingCycle::OneTime, BillingCycle::Free])
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $today->addDays((int) setting('billing.renewal_days_before')))
            ->where('recurring_amount', '>', 0)
            ->get()
            ->reject(fn (Service $service): bool => $this->alreadyInvoiced('service_id', $service->id, InvoiceItem::TYPE_SERVICE, $service->next_due_date));
    }

    /**
     * @return iterable<Domain>
     */
    private function dueDomains(CarbonImmutable $today): iterable
    {
        return Domain::query()
            ->with('client')
            ->whereIn('status', [DomainStatus::Active, DomainStatus::Expired])
            ->where('auto_renew', true)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $today->addDays((int) setting('domains.renewal_days_before')))
            ->where('recurring_amount', '>', 0)
            ->get()
            ->reject(fn (Domain $domain): bool => $this->alreadyInvoiced('domain_id', $domain->id, InvoiceItem::TYPE_DOMAIN_RENEW, $domain->next_due_date));
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, mixed>  $item
     */
    private function addToGroup(array &$groups, Client $client, string $currency, CarbonImmutable $due, array $item): void
    {
        $key = $client->id.'|'.$currency.'|'.$due->toDateString();

        $groups[$key] ??= ['client' => $client, 'currency' => $currency, 'due' => $due, 'items' => []];
        $groups[$key]['items'][] = $item;
    }

    private function alreadyInvoiced(string $column, int $id, string $type, CarbonImmutable $periodStart): bool
    {
        return InvoiceItem::query()
            ->where($column, $id)
            ->where('type', $type)
            ->whereDate('period_start', $periodStart)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->exists();
    }
}
