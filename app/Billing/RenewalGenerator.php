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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Creates renewal invoices for services and domains that are due soon. Safe to run many times a day,
 * even two runs at once: every renewal line carries a billing key that is unique in the database,
 * so a period that already has an invoice is never invoiced again.
 */
class RenewalGenerator
{
    public function __construct(
        private InvoiceManager $invoices,
        private TemplateMailer $mailer,
        private Wallet $wallet,
    ) {}

    /**
     * @return int The number of invoices created.
     */
    public function generate(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? today());

        /** @var array<string, array{client: Client, currency: string, due: CarbonImmutable, items: list<array<string, mixed>>}> $groups */
        $groups = [];

        /** @var array<int, Service> $discounted Services whose coupon was used on this run. */
        $discounted = [];

        foreach ($this->dueServices($today) as $service) {
            foreach ($this->serviceItems($service) as $item) {
                $this->addToGroup($groups, $service->client, $service->currency, $service->next_due_date, $item);

                if ($item['type'] === InvoiceItem::TYPE_DISCOUNT) {
                    $discounted[$service->id] = $service;
                }
            }
        }

        foreach ($this->dueDomains($today) as $domain) {
            $this->addToGroup($groups, $domain->client, $domain->currency, $domain->next_due_date, LineItems::domainPeriod(
                $domain,
                InvoiceItem::TYPE_DOMAIN_RENEW,
                max(1, $domain->years),
                $domain->recurring_amount,
                $domain->next_due_date,
            ) + ['billing_key' => self::billingKey('domain', $domain->id, $domain->next_due_date)]);
        }

        $created = 0;

        foreach ($groups as $group) {
            try {
                $invoice = $this->invoices->create($group['client'], $group['items'], dueAt: $group['due'], currency: $group['currency']);
            } catch (UniqueConstraintViolationException) {
                // Another run invoiced one of these periods a moment ago; its invoice stands.
                Log::info("Renewal invoice for client {$group['client']->id} skipped: a period on it is already invoiced.");

                continue;
            }

            $created++;

            foreach ($group['items'] as $item) {
                if ($item['type'] === InvoiceItem::TYPE_DISCOUNT && isset($discounted[$item['service_id']])) {
                    $this->useRenewalCoupon($discounted[$item['service_id']], $invoice, -$item['amount']);
                }
            }

            Activity::log('invoice.renewal', "Renewal invoice {$invoice->number} created", $invoice, $group['client']);
            $this->mailer->send('invoice.created', $group['client'], TemplateMailer::invoiceContext($invoice));
            $this->wallet->applyAutomatically($invoice);
        }

        return $created;
    }

    /**
     * The key that makes a billing period unique, for example "service:12:2026-10-01".
     */
    public static function billingKey(string $kind, int $id, CarbonInterface $periodStart): string
    {
        return $kind.':'.$id.':'.$periodStart->format('Y-m-d');
    }

    /**
     * The service's renewal line, its active add-ons, and its coupon discount if it still has one.
     *
     * @return list<array<string, mixed>>
     */
    private function serviceItems(Service $service): array
    {
        $start = $service->next_due_date;
        $amount = $service->recurring_amount;
        $billed = $service;

        // A downgrade planned for this renewal: bill the new plan from its first day.
        $planned = app(PlanChanges::class)->scheduledFor($service, $start);

        if ($planned !== null && $planned->toProduct !== null) {
            $amount = $planned->new_amount;
            $billed = (clone $service)->setRelation('product', $planned->toProduct);
        }

        $items = [LineItems::servicePeriod($billed, $start, $amount) + ['billing_key' => self::billingKey('service', $service->id, $start)]];

        foreach ($service->addons as $addon) {
            if ($addon->isActive() && $addon->recurring_amount > 0) {
                $items[] = LineItems::addonPeriod($service, $addon->name, $start, $addon->recurring_amount);
            }
        }

        $coupon = $service->coupon;

        if ($coupon !== null && ($service->coupon_payments_left === null || $service->coupon_payments_left > 0)) {
            $discount = $coupon->discountOn($amount);

            if ($discount > 0) {
                $items[] = LineItems::discount($coupon, $discount, $service);
            }
        }

        return $items;
    }

    private function useRenewalCoupon(Service $service, Invoice $invoice, int $amount): void
    {
        $service->coupon->redemptions()->create([
            'client_id' => $service->client_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => $invoice->currency,
        ]);

        if ($service->coupon_payments_left !== null) {
            $left = $service->coupon_payments_left - 1;
            $service->update(['coupon_payments_left' => $left, 'coupon_id' => $left > 0 ? $service->coupon_id : null]);
        }
    }

    /**
     * The invoice for a domain's next period, created now if it does not exist yet. Used when a
     * client or staff member wants to renew before the automatic renewal invoice.
     */
    public function invoiceDomainRenewal(Domain $domain): Invoice
    {
        $domain->loadMissing('client');
        $start = $domain->next_due_date ?? $domain->expires_at ?? CarbonImmutable::today();

        $existing = fn (): ?InvoiceItem => InvoiceItem::query()
            ->with('invoice')
            ->where('domain_id', $domain->id)
            ->where('type', InvoiceItem::TYPE_DOMAIN_RENEW)
            ->whereDate('period_start', $start)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->latest('id')
            ->first();

        if (($found = $existing()) !== null) {
            return $found->invoice;
        }

        $years = max(1, $domain->years);
        $amount = $domain->recurring_amount ?: (int) TldPrice::forTld($domain->tld, $domain->currency)?->priceFor('renew', $years);

        try {
            $invoice = $this->invoices->create(
                $domain->client,
                [LineItems::domainPeriod($domain, InvoiceItem::TYPE_DOMAIN_RENEW, $years, $amount, $start) + ['billing_key' => self::billingKey('domain', $domain->id, $start)]],
                dueAt: CarbonImmutable::today(),
                currency: $domain->currency,
            );
        } catch (UniqueConstraintViolationException $exception) {
            // Made a moment ago, for example by a double click.
            return $existing()?->invoice ?? throw $exception;
        }

        Activity::log('invoice.renewal', "Renewal invoice {$invoice->number} created for {$domain->name}", $invoice, $domain->client);
        $this->mailer->send('invoice.created', $domain->client, TemplateMailer::invoiceContext($invoice));
        $this->wallet->applyAutomatically($invoice);

        return $invoice->refresh();
    }

    /**
     * @return iterable<Service>
     */
    private function dueServices(CarbonImmutable $today): iterable
    {
        return Service::query()
            ->with('product', 'client', 'addons', 'coupon')
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
