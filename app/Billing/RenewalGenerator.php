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
use App\Models\ResourceChange;
use App\Models\ResourceChangeItem;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Models\ServiceResourceOption;
use App\Models\TldPrice;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates renewal invoices for services and domains that are due soon. Safe to run many times a day,
 * even two runs at once: every renewal line carries a billing key that is unique in the database,
 * so a period that already has an invoice is never invoiced again.
 */
class RenewalGenerator
{
    /**
     * What a due service is loaded with: everything its renewal lines read.
     *
     * @var list<string>
     */
    private const SERVICE_RELATIONS = ['product', 'client', 'addons', 'coupon', 'resources.option'];

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

        // Free renewals made before they were paid at once (0.6.11 and older) are paid now. A domain
        // renewal that is free only because its price was missing stays unpaid.
        Invoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->where('total', 0)
            ->whereHas('items', fn (Builder $query) => $query->whereNotNull('billing_key'))
            ->whereDoesntHave('items', fn (Builder $query) => self::unpricedDomainRenewal($query))
            ->eachById(fn (Invoice $invoice) => app(PaymentRecorder::class)->settleFreeInvoice($invoice));

        /** @var array<string, array{client: Client, currency: string, due: CarbonImmutable, items: list<array<string, mixed>>}> $groups */
        $groups = [];

        /** @var array<int, Service> $discounted Services whose coupon was used on this run. */
        $discounted = [];

        $planChanges = app(PlanChanges::class);

        foreach ($this->dueServices($today) as $service) {
            // An upgrade still waiting for payment was priced for the period that ends now. It stops
            // before the next period is billed, or is applied first when it was just paid.
            try {
                if ($planChanges->settleBeforeRenewal($service)) {
                    $service = $service->fresh(self::SERVICE_RELATIONS);
                }
            } catch (Throwable $exception) {
                // One upgrade that cannot be settled never stops the run: it stays waiting, staff see
                // why, and the service renews on the plan it is on now.
                report($exception);
                rescue(fn () => Activity::log('service.plan_change_failed', "Pending plan change for service #{$service->id} could not be settled before the renewal: ".self::reason($exception), $service, $service->client));
                $service = $service->fresh(self::SERVICE_RELATIONS) ?? $service;
            }

            // The same for a change of extras still waiting: it stops, or is applied when just paid.
            try {
                if ($service !== null && $this->hasPendingResourceChange($service) && app(ResourceChanges::class)->settleBeforeRenewal($service)) {
                    $service = $service->fresh(self::SERVICE_RELATIONS);
                }
            } catch (Throwable $exception) {
                report($exception);
                rescue(fn () => Activity::log('service.resource_change_failed', "Pending resource change for service #{$service->id} could not be settled before the renewal: ".self::reason($exception), $service, $service->client));
                $service = $service->fresh(self::SERVICE_RELATIONS) ?? $service;
            }

            if ($service === null) {
                continue;
            }

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
            } catch (Throwable $exception) {
                // One invoice that cannot be saved is reported, and the other clients still get theirs.
                // Staff see it on the client's page too: until it is fixed, this client is not invoiced.
                report($exception);
                rescue(fn () => Activity::log('invoice.renewal_failed', "Renewal invoice for client #{$group['client']->id} could not be created: ".self::reason($exception), $group['client'], $group['client']));

                continue;
            }

            $created++;

            foreach ($group['items'] as $item) {
                if ($item['type'] === InvoiceItem::TYPE_DISCOUNT && isset($discounted[$item['service_id']])) {
                    $this->useRenewalCoupon($discounted[$item['service_id']], $invoice, -$item['amount']);
                }
            }

            Activity::log('invoice.renewal', "Renewal invoice {$invoice->number} created", $invoice, $group['client']);
            $this->sendOrSettle($invoice, $group['client']);
        }

        return $created;
    }

    /**
     * Why an invoice could not be saved, for the activity log. A database error keeps only the
     * database's own message, without the server details and the SQL that Laravel adds.
     */
    private static function reason(Throwable $exception): string
    {
        return $exception instanceof QueryException
            ? (string) $exception->getPrevious()?->getMessage()
            : $exception->getMessage();
    }

    /**
     * The key that makes a billing period unique, for example "service:12:2026-10-01".
     */
    public static function billingKey(string $kind, int $id, CarbonInterface $periodStart): string
    {
        return $kind.':'.$id.':'.$periodStart->format('Y-m-d');
    }

    /**
     * The service's renewal line, its active add-ons, its extras, and its coupon discount if it
     * still has one that covers the plan billed. A coupon only ever takes money off the plan price.
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

        $items = [...$items, ...$this->resourceItems($service, $start)];

        // Checked against the plan this renewal bills, so a coupon made for one plan gives nothing
        // after the service moved to another.
        $coupon = $service->renewalCoupon($billed->product);

        if ($coupon !== null) {
            $discount = $coupon->discountOn($amount);

            if ($discount > 0) {
                $items[] = LineItems::discount($coupon, $discount, $service);
            }
        }

        return $items;
    }

    /**
     * One line per extra the service keeps for the next period, at the price the service pays for
     * it. A change of extras planned for this renewal is billed as it will be: its rows at their new
     * steps and price, and kinds it adds at their new price. Rows that are cancelled, at 0 steps or
     * free are not billed. These lines need no billing key: the service line's key already makes the
     * whole renewal unique.
     *
     * @return list<array<string, mixed>>
     */
    private function resourceItems(Service $service, CarbonImmutable $start): array
    {
        $planned = $this->scheduledResources($service, $start)?->items->keyBy('kind') ?? collect();
        $items = [];
        // In the order the store shows the options; rows whose option is gone come last.
        $rows = $service->resources->sortBy([
            fn (ServiceResourceOption $a, ServiceResourceOption $b): int => ($a->option?->sort_order ?? PHP_INT_MAX) <=> ($b->option?->sort_order ?? PHP_INT_MAX),
            fn (ServiceResourceOption $a, ServiceResourceOption $b): int => $a->id <=> $b->id,
        ]);

        foreach ($rows as $row) {
            $change = $planned->pull($row->kind);
            $steps = $change?->to_steps ?? ($row->isActive() ? $row->steps : 0);
            $amount = $change?->new_amount ?? $row->recurring_amount;

            if ($steps > 0 && $amount > 0) {
                $items[] = LineItems::resourcePeriod($service, $row, $start, $amount, $steps);
            }

            // Steps a waiting change adds have no invoice of their own: their setup fee is billed here.
            if ($change !== null && $change->setup_fee > 0) {
                $items[] = LineItems::resourceSetupFee($service, self::addedSteps($row, $change), (int) $change->setup_fee);
            }
        }

        foreach ($planned as $change) {
            if ($change->to_steps > 0 && $change->new_amount > 0) {
                // A kind the service gets on this day: described from the change, not saved yet.
                $row = (new ServiceResourceOption([
                    'kind' => $change->kind,
                    'unit' => $change->unit,
                    'step_size' => $change->step_size,
                    'steps' => $change->to_steps,
                ]))->setRelation('option', $change->option);

                $items[] = LineItems::resourcePeriod($service, $row, $start, $change->new_amount);
            }

            if ($change->setup_fee > 0) {
                $items[] = LineItems::resourceSetupFee($service, self::addedSteps(new ServiceResourceOption, $change), (int) $change->setup_fee);
            }
        }

        return $items;
    }

    /**
     * The row as the steps a change adds, so a setup fee line says what it is for: "RAM +4 GB".
     */
    private static function addedSteps(ServiceResourceOption $row, ResourceChangeItem $change): ServiceResourceOption
    {
        $added = clone $row;
        $added->forceFill([
            'kind' => $change->kind,
            'unit' => $change->unit,
            'step_size' => $change->step_size,
            'steps' => max(0, $change->to_steps - $change->from_steps),
            'status' => ServiceResourceOption::STATUS_ACTIVE,
        ]);

        return $added->setRelation('option', $change->option ?? $row->option);
    }

    /**
     * The change of extras planned for the period that starts on this date (one that waits for the
     * renewal), so the renewal bills the new steps.
     */
    private function scheduledResources(Service $service, CarbonInterface $periodStart): ?ResourceChange
    {
        return ResourceChange::query()->with('items.option')
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_PENDING)
            ->where('mode', ResourceChange::MODE_RENEWAL)
            ->whereDate('apply_on', $periodStart)
            ->latest('id')
            ->first();
    }

    /**
     * Whether a change of the service's extras still waits for payment, so it is settled before the
     * service renews (see ResourceChanges::settleBeforeRenewal()).
     */
    private function hasPendingResourceChange(Service $service): bool
    {
        return ResourceChange::query()
            ->where('service_id', $service->id)
            ->where('status', ResourceChange::STATUS_PENDING)
            ->where('mode', ResourceChange::MODE_INVOICE)
            ->exists();
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
     *
     * @throws RenewalUnavailable When no renewal price is known for the domain; nothing is made then.
     */
    public function invoiceDomainRenewal(Domain $domain): Invoice
    {
        $domain->loadMissing('client');
        $start = $domain->next_due_date ?? $domain->expires_at ?? CarbonImmutable::today();

        // Paying renews the domain only when the paid period starts on its next due date, so a
        // domain without one gets the period's start date now.
        $keepDueDate = function () use ($domain, $start): void {
            if ($domain->next_due_date === null) {
                $domain->update(['next_due_date' => $start]);
            }
        };

        $existing = fn (): ?InvoiceItem => InvoiceItem::query()
            ->with('invoice')
            ->where('domain_id', $domain->id)
            ->where('type', InvoiceItem::TYPE_DOMAIN_RENEW)
            ->whereDate('period_start', $start)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->latest('id')
            ->first();

        if (($found = $existing()) !== null) {
            $keepDueDate();

            return $found->invoice;
        }

        $years = max(1, $domain->years);
        $amount = $this->domainRenewalAmount($domain);

        // A missing price is not a free renewal: the registrar would bill the company for it.
        if ($amount <= 0) {
            throw new RenewalUnavailable(__('This domain has no renewal price. Set its recurring amount, or renew it without an invoice.'));
        }

        $keepDueDate();

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
        $this->sendOrSettle($invoice, $domain->client);

        return $invoice->refresh();
    }

    /**
     * What renewing the domain for its usual number of years costs, in minor units: its own
     * recurring amount, or the renew price of its extension in its currency. 0 when neither is known.
     */
    public function domainRenewalAmount(Domain $domain): int
    {
        return (int) ($domain->recurring_amount ?: TldPrice::forTld($domain->tld, $domain->currency)?->priceFor('renew', max(1, $domain->years)));
    }

    /**
     * Email a new renewal invoice and pay it from the wallet when that is on. A free one (a coupon,
     * or a move to a free plan) is paid at once, like a free order, so the period renews and nothing
     * goes overdue. A domain renewal with no price is never paid this way: the registrar would bill
     * the company for it.
     */
    private function sendOrSettle(Invoice $invoice, Client $client): void
    {
        if ($invoice->total === 0 && ! self::unpricedDomainRenewal($invoice->items()->getQuery())->exists()) {
            app(PaymentRecorder::class)->settleFreeInvoice($invoice);

            return;
        }

        $this->mailer->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));
        $this->wallet->applyAutomatically($invoice);
    }

    /**
     * Narrows invoice lines to domain renewals that cost nothing, which only a missing price makes.
     */
    private static function unpricedDomainRenewal(Builder $query): Builder
    {
        return $query->where('type', InvoiceItem::TYPE_DOMAIN_RENEW)->where('amount', '<=', 0);
    }

    /**
     * @return iterable<Service>
     */
    private function dueServices(CarbonImmutable $today): iterable
    {
        return Service::query()
            ->with(self::SERVICE_RELATIONS)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereNotIn('billing_cycle', [BillingCycle::OneTime, BillingCycle::Free])
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $today->addDays((int) setting('billing.renewal_days_before')))
            // A free plan still renews when it has a paid add-on or paid extras.
            ->where(fn (Builder $query) => $query
                ->where('recurring_amount', '>', 0)
                ->orWhereHas('addons', fn (Builder $query) => $query->where('status', ServiceAddon::STATUS_ACTIVE)->where('recurring_amount', '>', 0))
                ->orWhereHas('resources', fn (Builder $query) => $query->where('status', ServiceResourceOption::STATUS_ACTIVE)->where('steps', '>', 0)->where('recurring_amount', '>', 0)))
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
