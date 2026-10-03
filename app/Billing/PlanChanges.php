<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a service to a bigger or smaller product. The price is fair for the rest of the billing
 * period: the unused part of the old plan is set against the cost of the new plan for the same
 * days. An upgrade is invoiced and happens once paid; a downgrade happens now with the unused
 * amount added to the client's wallet, or on the next renewal date (Settings → General → Billing).
 */
class PlanChanges
{
    public function __construct(
        private InvoiceManager $invoices,
        private Wallet $wallet,
        private Provisioner $provisioner,
        private TemplateMailer $mailer,
    ) {}

    public function enabled(): bool
    {
        return (bool) setting('billing.plan_changes');
    }

    /**
     * Why the service cannot change plan right now, or null when it can.
     */
    public function blockedReason(Service $service): ?string
    {
        return match (true) {
            $service->status !== ServiceStatus::Active => __('Only active services can change plan.'),
            ! $service->billing_cycle->isRecurring() || $service->next_due_date === null => __('This service does not renew, so its plan cannot change.'),
            $this->pendingFor($service) !== null => __('A plan change for this service is already waiting.'),
            $this->hasUnpaidInvoice($service) => __('Pay the open invoice for this service first.'),
            default => null,
        };
    }

    /**
     * The products the service may move to, with a price in its currency and billing cycle. Clients
     * get the products chosen on the product that are in stock; staff may pick any product with the
     * same server module.
     *
     * @return Collection<int, Product>
     */
    public function targets(Service $service, bool $staff = false): Collection
    {
        $service->loadMissing('product');
        $query = Product::query()->with('prices', 'group')->whereKeyNot($service->product_id)->orderBy('sort_order')->orderBy('name');

        if (! $staff) {
            $query->whereIn('id', array_map('intval', (array) ($service->product->upgrade_product_ids ?? [])));
        }

        return $query->get()
            ->filter(fn (Product $product): bool => $product->server_module === $service->product->server_module
                && $product->priceFor($service->currency, $service->billing_cycle) !== null
                && ($staff || $product->isInStock()))
            ->values();
    }

    /**
     * The fair price of moving to the product today, in minor units.
     *
     * @return array{old: int, new: int, days_left: int, period_days: int, credit: int, cost: int, difference: int}
     */
    public function quote(Service $service, Product $product, ?CarbonInterface $today = null): array
    {
        $today = CarbonImmutable::instance($today ?? today())->startOfDay();
        $end = $service->next_due_date;
        $start = $end->subMonthsNoOverflow((int) $service->billing_cycle->months());
        $periodDays = max(1, (int) round($start->diffInDays($end)));
        $daysLeft = max(0, min($periodDays, (int) round($today->diffInDays($end, false))));

        $old = (int) $service->recurring_amount;
        $new = (int) $product->priceFor($service->currency, $service->billing_cycle)?->price;
        $share = $this->paidShare($service);
        // Unused time is worth what the client really paid for it: nothing after a free coupon or trial,
        // and never more than was paid for these days, even when the plan changed without a payment.
        $credit = (int) round($old * $share * $daysLeft / $periodDays);
        $paidLeft = $this->paidForDaysLeft($service, $end, $daysLeft, $periodDays);

        if ($paidLeft !== null) {
            $credit = min($credit, $paidLeft);
        }

        // A bigger plan costs its full price for the days left. A smaller one gets the same discount as
        // the period, so moving down never costs money and never pays out more than was paid.
        $cost = (int) round($new * ($new >= $old ? 1 : $share) * $daysLeft / $periodDays);

        if ($new < $old) {
            $cost = min($cost, $credit);
        }

        return [
            'old' => $old,
            'new' => $new,
            'days_left' => $daysLeft,
            'period_days' => $periodDays,
            'credit' => $credit,
            'cost' => $cost,
            'difference' => $cost - $credit,
        ];
    }

    /**
     * The share of the plan's price the client paid for the current period, from 0 to 1: the latest
     * paid line for the service, less the coupon on the same invoice and less what credit notes gave
     * back. A free trial, a 100% coupon or a refunded period is 0. A service with no paid invoice here
     * (imported, or made by staff) keeps its full price.
     */
    private function paidShare(Service $service): float
    {
        $line = InvoiceItem::query()
            ->with('invoice.creditNotes')
            ->where('service_id', $service->id)
            ->where('type', InvoiceItem::TYPE_SERVICE)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Refunded]))
            ->latest('id')
            ->first();

        if ($line === null) {
            return 1.0;
        }

        if ($line->amount <= 0) {
            return 0.0;
        }

        $share = ($line->amount - $this->discountFor($line)) / $line->amount;

        return max(0.0, min(1.0, $share * $this->keptShare($line->invoice)));
    }

    /**
     * What the client paid for the days left of the current period, in minor units: the period's
     * paid service line less its coupon, plus upgrades paid for these days, less credit notes. Null
     * when no paid service line covers the period (an imported service, or one made by staff).
     */
    private function paidForDaysLeft(Service $service, CarbonImmutable $end, int $daysLeft, int $periodDays): ?int
    {
        $lastDay = $end->subDay();
        $lines = InvoiceItem::query()
            ->with('invoice.creditNotes')
            ->where('service_id', $service->id)
            ->whereIn('type', [InvoiceItem::TYPE_SERVICE, InvoiceItem::TYPE_PLAN_CHANGE])
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereDate('period_start', '<=', $lastDay)
            ->whereDate('period_end', '>=', $lastDay)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Refunded]))
            ->latest('id')
            ->get();

        $period = $lines->firstWhere('type', InvoiceItem::TYPE_SERVICE);

        if ($period === null) {
            return null;
        }

        $paid = max(0, $period->amount - $this->discountFor($period)) * $this->keptShare($period->invoice) * $daysLeft / $periodDays;

        foreach ($lines->where('type', InvoiceItem::TYPE_PLAN_CHANGE) as $line) {
            // An upgrade paid for the days from its start to the end of the period.
            $days = max(1, (int) round($line->period_start->diffInDays($line->period_end)) + 1);
            $paid += max(0, $line->amount) * $this->keptShare($line->invoice) * min($daysLeft, $days) / $days;
        }

        return (int) round($paid);
    }

    /**
     * The coupon taken off a service line on the same invoice, as a positive amount.
     */
    private function discountFor(InvoiceItem $line): int
    {
        return -(int) InvoiceItem::query()
            ->where('invoice_id', $line->invoice_id)
            ->where('service_id', $line->service_id)
            ->where('type', InvoiceItem::TYPE_DISCOUNT)
            ->sum('amount');
    }

    /**
     * The part of a paid invoice the client still pays for, from 0 to 1: less after a partial credit
     * note, and 0 once it was refunded.
     */
    private function keptShare(Invoice $invoice): float
    {
        if ($invoice->status === InvoiceStatus::Refunded) {
            return 0.0;
        }

        if ($invoice->total <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $invoice->creditableAmount() / $invoice->total));
    }

    /**
     * How a change with this price difference happens. A downgrade waits for the renewal when its
     * money back cannot go into the client's wallet (wallet off, or in another currency).
     */
    public function modeFor(int $difference, ?Service $service = null): string
    {
        return match (true) {
            $difference > 0 => PlanChange::MODE_INVOICE,
            $difference < 0 && (setting('billing.downgrade') === 'renewal' || ($service !== null && ! $this->canCredit($service))) => PlanChange::MODE_RENEWAL,
            default => PlanChange::MODE_NOW,
        };
    }

    /**
     * Whether money back for the service can go into its client's wallet.
     */
    private function canCredit(Service $service): bool
    {
        return $this->wallet->enabled() && $service->client?->currency === $service->currency;
    }

    /**
     * Start moving the service to the product. Staff may change it now without charging anything.
     *
     * @throws RuntimeException When the service cannot move to that product.
     */
    public function start(Service $service, Product $product, ?Admin $admin = null, bool $charge = true): PlanChange
    {
        $change = DB::transaction(function () use ($service, $product, $admin, $charge): PlanChange {
            $locked = Service::query()->lockForUpdate()->findOrFail($service->id);
            $locked->setRelation('product', $service->product);

            $reason = $this->blockedReason($locked);

            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            if (! $this->targets($locked, $admin !== null)->contains('id', $product->id)) {
                throw new RuntimeException(__('This service cannot move to that plan.'));
            }

            // Two clients cannot both take the last one of a plan with a stock limit. Staff may.
            if ($admin === null && $product->stock !== null && Product::query()->lockForUpdate()->findOrFail($product->id)->stockLeft() === 0) {
                throw new RuntimeException(__('This plan is sold out.'));
            }

            $quote = $this->quote($locked, $product);
            $mode = $admin !== null && ! $charge ? PlanChange::MODE_NOW : $this->modeFor($quote['difference'], $locked);

            $change = PlanChange::create([
                'service_id' => $locked->id,
                'client_id' => $locked->client_id,
                'admin_id' => $admin?->id,
                'from_product_id' => $locked->product_id,
                'to_product_id' => $product->id,
                'billing_cycle' => $locked->billing_cycle,
                'currency' => $locked->currency,
                'old_amount' => $quote['old'],
                'new_amount' => $quote['new'],
                'difference' => $admin !== null && ! $charge ? 0 : $quote['difference'],
                'mode' => $mode,
                'status' => PlanChange::STATUS_PENDING,
                'apply_on' => $mode === PlanChange::MODE_RENEWAL ? $locked->next_due_date : null,
            ]);

            if ($mode === PlanChange::MODE_INVOICE) {
                $end = $locked->next_due_date->subDay();
                $invoice = $this->invoices->create($locked->client, [[
                    'type' => InvoiceItem::TYPE_PLAN_CHANGE,
                    'description' => $service->product->name.' → '.$product->name.($locked->domain ? ' - '.$locked->domain : '')
                        .' ('.today()->format('d M Y').' - '.$end->format('d M Y').')',
                    'amount' => $quote['difference'],
                    'service_id' => $locked->id,
                    'taxed' => $product->taxable,
                    // The days the price is for; paying it later than this period changes nothing.
                    'period_start' => CarbonImmutable::today(),
                    'period_end' => $end,
                ]], dueAt: today(), currency: $locked->currency);

                $change->update(['invoice_id' => $invoice->id]);
            }

            return $change;
        });

        Activity::log('service.plan_change', "Plan change for service #{$service->id}: {$service->product->name} → {$product->name} ({$change->mode})", $service, actor: $admin);

        match ($change->mode) {
            PlanChange::MODE_INVOICE => $this->sendInvoice($change),
            PlanChange::MODE_NOW => $this->apply($change),
            default => null,
        };

        return $change->refresh();
    }

    /**
     * Move the service to its new product, once. Returns false when the change was not waiting, or
     * when it can no longer happen; it is then stopped and a paid invoice goes back to the wallet.
     */
    public function apply(PlanChange $change): bool
    {
        $credit = 0;
        $notCredited = 0;
        $stopped = null;

        $applied = DB::transaction(function () use ($change, &$credit, &$notCredited, &$stopped): bool {
            $locked = PlanChange::query()->lockForUpdate()->find($change->id);

            if ($locked === null || ! $locked->isPending()) {
                return false;
            }

            $service = Service::query()->lockForUpdate()->findOrFail($locked->service_id);
            $stopped = $this->stopReason($locked, $service);

            if ($stopped !== null) {
                $locked->update(['status' => PlanChange::STATUS_CANCELLED]);

                return false;
            }

            $service->update(['product_id' => $locked->to_product_id, 'recurring_amount' => $locked->new_amount]);
            $locked->update(['status' => PlanChange::STATUS_APPLIED, 'applied_at' => now()]);

            $service->loadMissing('client');

            if ($locked->mode === PlanChange::MODE_NOW && $locked->difference < 0) {
                if ($this->wallet->enabled() && $service->client->currency === $locked->currency) {
                    $credit = -$locked->difference;
                    $this->wallet->change($service->client, $credit, __('Unused time of your old plan for :service', ['service' => $service->domain ?: '#'.$service->id]));
                } else {
                    $notCredited = -$locked->difference;
                }
            }

            return true;
        });

        if ($stopped !== null) {
            $this->stopped($change->refresh(), $stopped);

            return false;
        }

        if (! $applied) {
            return false;
        }

        $change->refresh()->load('service.client', 'service.product', 'fromProduct', 'toProduct');
        $service = $change->service;

        if ($notCredited > 0) {
            Activity::log('service.plan_change_credit', 'The '.money($notCredited, $change->currency)." for the unused time of the old plan of service #{$service->id} could not go into the client's wallet (wallet off or another currency). Settle it by hand.", $service);
        }

        if ($service->product->server_module !== null && $service->server_id !== null) {
            // A failed server update is logged for staff; the new plan and price stand.
            $this->provisioner->changePackage($service);
        }

        Activity::log('service.plan_changed', "Service #{$service->id} moved from {$change->fromProduct?->name} to {$change->toProduct?->name}", $service);

        $this->mailer->send('service.plan_changed', $service->client, TemplateMailer::serviceContext($service) + [
            'plan' => [
                'old' => $change->fromProduct?->name,
                'new' => $change->toProduct?->name,
                'amount' => money($change->new_amount, $change->currency),
                'cycle' => Locales::in(Locales::forClient($service->client), fn (): string => $change->billing_cycle->label()),
                'note' => $credit > 0 ? Locales::in(Locales::forClient($service->client), fn (): string => __('We added :amount to your wallet for the unused time of your old plan.', ['amount' => money($credit, $change->currency)])) : '',
            ],
        ]);

        return true;
    }

    /**
     * Why a waiting change can no longer happen, in words for the client, or null when it can.
     */
    private function stopReason(PlanChange $change, Service $service): ?string
    {
        $product = Product::query()->find($change->to_product_id);

        if ($product === null) {
            return __('The new plan is no longer sold.');
        }

        if ($change->mode === PlanChange::MODE_INVOICE && $this->periodPassed($change, $service)) {
            return __('The service renewed before the plan change was paid.');
        }

        // The last one of a plan in stock may have gone while the client's change waited.
        if ($change->admin_id === null && $change->mode !== PlanChange::MODE_RENEWAL && $product->stockLeft() === 0) {
            return __('The new plan is sold out.');
        }

        return null;
    }

    /**
     * Whether the period an upgrade was priced for has passed: the service renewed since, or its
     * next period is already invoiced at the old price, paid or not.
     */
    private function periodPassed(PlanChange $change, Service $service): bool
    {
        if ($change->invoice_id === null) {
            return false;
        }

        // The upgrade's line runs to the day before the next due date it was priced for.
        $dueDate = InvoiceItem::query()
            ->where('invoice_id', $change->invoice_id)
            ->where('type', InvoiceItem::TYPE_PLAN_CHANGE)
            ->first()?->period_end?->addDay();

        if ($dueDate !== null && $service->next_due_date?->isSameDay($dueDate) !== true) {
            return true;
        }

        return InvoiceItem::query()
            ->where('service_id', $service->id)
            ->where('type', InvoiceItem::TYPE_SERVICE)
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', InvoiceStatus::Cancelled))
            ->when(
                $dueDate !== null,
                fn ($query) => $query->whereDate('period_start', '>=', $dueDate),
                // Older upgrades do not keep their period: any renewal invoiced after the upgrade.
                fn ($query) => $query->where('invoice_id', '>', $change->invoice_id),
            )
            ->exists();
    }

    /**
     * Log a change that stopped, and give back the invoice paid for it: a credit note puts the money
     * in the client's wallet, or staff are asked to settle it when it cannot go there.
     */
    private function stopped(PlanChange $change, string $reason): void
    {
        $change->loadMissing('service', 'invoice.client');
        Activity::log('service.plan_change_stopped', "Plan change for service #{$change->service_id} stopped: {$reason}", $change->service);

        $invoice = $change->invoice;

        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid || $invoice->creditableAmount() <= 0) {
            return;
        }

        $credited = $this->wallet->enabled() && $invoice->currency === $invoice->client->currency
            && rescue(fn (): bool => app(CreditNotes::class)->issue($invoice, $invoice->creditableAmount(), CreditNote::METHOD_WALLET, $reason) instanceof CreditNote, false);

        if (! $credited) {
            Activity::log('service.plan_change_refund', "Invoice {$invoice->displayNumber()} paid for a plan change that stopped. Give the money back to the client by hand.", $invoice);
        }
    }

    /**
     * Before the service's next period is billed. An upgrade still waiting for payment was priced for
     * the period that ends now, so it stops: what was paid of its invoice goes back to the wallet and
     * the invoice is cancelled. One whose invoice was just paid is applied first. One with a card or
     * gateway payment still going through stays open; if that payment lands after the renewal was
     * billed, the change stops then and the money goes back to the wallet. Returns true when the
     * service moved to its new plan, so the renewal bills the new plan.
     */
    public function settleBeforeRenewal(Service $service): bool
    {
        $change = PlanChange::query()
            ->where('service_id', $service->id)
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_INVOICE)
            ->latest('id')
            ->first();

        if ($change === null) {
            return false;
        }

        $returned = 0;
        $notReturned = 0;

        // The change and then its invoice are locked (the invoice lock a payment takes), so a payment
        // arriving now either lands first and applies the change, or finds the invoice cancelled.
        $outcome = DB::transaction(function () use ($change, &$returned, &$notReturned): string {
            $locked = PlanChange::query()->lockForUpdate()->find($change->id);

            if ($locked === null || ! $locked->isPending()) {
                return $locked?->status === PlanChange::STATUS_APPLIED ? 'applied' : 'gone';
            }

            $invoice = $locked->invoice_id === null ? null : Invoice::query()->lockForUpdate()->find($locked->invoice_id);

            if ($invoice?->status === InvoiceStatus::Paid) {
                return 'paid';
            }

            if ($invoice?->status === InvoiceStatus::Unpaid && PaymentUnderway::on($invoice)) {
                return 'waiting';
            }

            if ($invoice?->status === InvoiceStatus::Unpaid && $invoice->amount_paid > 0) {
                $invoice->load('client');

                if ($this->wallet->enabled() && $invoice->currency === $invoice->client->currency) {
                    $this->wallet->change($invoice->client, $invoice->amount_paid, __('Money back for the plan change on invoice :number', ['number' => $invoice->displayNumber()]), $invoice);
                    $returned = $invoice->amount_paid;
                } else {
                    $notReturned = $invoice->amount_paid;
                }
            }

            if ($invoice !== null) {
                // Cancelling the invoice cancels the change with it.
                $this->invoices->cancel($invoice);
            }

            $locked->update(['status' => PlanChange::STATUS_CANCELLED]);

            return 'stopped';
        });

        return match ($outcome) {
            'paid' => $this->apply($change),
            // A payment got there first and moved the service already.
            'applied' => true,
            'stopped' => $this->expired($change, $service, $returned, $notReturned),
            default => false,
        };
    }

    /**
     * Log an upgrade that stopped because it was not paid before the renewal, and ask staff to
     * settle a part payment that could not go back into the wallet.
     */
    private function expired(PlanChange $change, Service $service, int $returned, int $notReturned): bool
    {
        $invoice = $change->invoice()->first();
        $number = $invoice?->displayNumber() ?? '';

        Activity::log('service.plan_change_expired', "Plan change for service #{$service->id} stopped: its invoice {$number} was not paid before the renewal"
            .($returned > 0 ? '; '.money($returned, $change->currency).' paid on it went back to the wallet' : ''), $service);

        if ($notReturned > 0) {
            Activity::log('service.plan_change_refund', "Invoice {$number} had ".money($notReturned, $change->currency).' paid on it for a plan change that stopped. Give the money back to the client by hand.', $invoice);
        }

        return false;
    }

    /**
     * Apply the changes waiting for this invoice, now that it is paid. One that fails is reported
     * and does not stop the rest of the payment.
     */
    public function applyForInvoice(Invoice $invoice): void
    {
        PlanChange::query()->where('invoice_id', $invoice->id)->where('status', PlanChange::STATUS_PENDING)->get()
            ->each(fn (PlanChange $change): bool => (bool) rescue(fn (): bool => $this->apply($change), false));
    }

    /**
     * The invoice for these changes was cancelled, so the changes are too.
     */
    public function cancelForInvoice(Invoice $invoice): void
    {
        PlanChange::query()->where('invoice_id', $invoice->id)->where('status', PlanChange::STATUS_PENDING)
            ->update(['status' => PlanChange::STATUS_CANCELLED]);
    }

    /**
     * Stop a waiting change. A change for the next renewal can only stop before that renewal is
     * invoiced; an unpaid upgrade invoice is cancelled with it.
     *
     * @throws RuntimeException When it can no longer stop.
     */
    public function cancel(PlanChange $change): void
    {
        if (! $change->isPending()) {
            return;
        }

        if ($change->mode === PlanChange::MODE_RENEWAL && $this->renewalInvoiced($change)) {
            throw new RuntimeException(__('The renewal with the new plan is already invoiced, so this change can no longer stop.'));
        }

        // The invoice goes first and is read again under a lock: money paid on it goes back to the
        // wallet. When that cannot happen, or a payment settled it meanwhile, the change stays.
        if ($change->invoice !== null && $this->invoices->cancel($change->invoice)->status === InvoiceStatus::Paid) {
            throw new RuntimeException(__('The invoice for this change is already paid, so the change can no longer stop.'));
        }

        // Only a change that is still waiting stops; one applied in the meantime stays applied.
        PlanChange::query()->whereKey($change->id)->where('status', PlanChange::STATUS_PENDING)->update(['status' => PlanChange::STATUS_CANCELLED]);
        $change->refresh();

        Activity::log('service.plan_change_cancelled', "Plan change for service #{$change->service_id} stopped", $change->service);
    }

    /**
     * Apply the downgrades planned for renewal dates up to today. Returns how many were applied. One
     * that fails is reported and skipped, so it never stops the nightly run.
     */
    public function applyScheduled(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? today());

        return PlanChange::query()
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_RENEWAL)
            ->whereDate('apply_on', '<=', $today)
            ->get()
            ->filter(fn (PlanChange $change): bool => (bool) rescue(fn (): bool => $this->apply($change), false))
            ->count();
    }

    public function pendingFor(Service $service): ?PlanChange
    {
        return PlanChange::query()->with('toProduct', 'invoice')->where('service_id', $service->id)->where('status', PlanChange::STATUS_PENDING)->latest('id')->first();
    }

    /**
     * The downgrade planned for the period that starts on this date, so the renewal bills the new plan.
     */
    public function scheduledFor(Service $service, CarbonInterface $periodStart): ?PlanChange
    {
        return PlanChange::query()->with('toProduct')
            ->where('service_id', $service->id)
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_RENEWAL)
            ->whereDate('apply_on', $periodStart)
            ->first();
    }

    private function hasUnpaidInvoice(Service $service): bool
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Draft])
            ->whereHas('items', fn ($query) => $query->where('service_id', $service->id))
            ->exists();
    }

    private function renewalInvoiced(PlanChange $change): bool
    {
        return InvoiceItem::query()
            ->where('billing_key', RenewalGenerator::billingKey('service', $change->service_id, $change->apply_on))
            ->exists();
    }

    private function sendInvoice(PlanChange $change): void
    {
        $invoice = $change->invoice()->with('client')->first();

        if ($invoice === null) {
            return;
        }

        $this->mailer->send('invoice.created', $invoice->client, TemplateMailer::invoiceContext($invoice));
        // Wallet money pays it straight away when the client has some; paying applies the change.
        $this->wallet->applyAutomatically($invoice);
    }
}
