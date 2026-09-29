<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
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
     * get the products chosen on the product; staff may pick any product with the same server module.
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
                && $product->priceFor($service->currency, $service->billing_cycle) !== null)
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
        $credit = (int) round($old * $daysLeft / $periodDays);
        $cost = (int) round($new * $daysLeft / $periodDays);

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
     * How a change with this price difference happens.
     */
    public function modeFor(int $difference): string
    {
        return match (true) {
            $difference > 0 => PlanChange::MODE_INVOICE,
            setting('billing.downgrade') === 'renewal' && $difference < 0 => PlanChange::MODE_RENEWAL,
            default => PlanChange::MODE_NOW,
        };
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

            $quote = $this->quote($locked, $product);
            $mode = $admin !== null && ! $charge ? PlanChange::MODE_NOW : $this->modeFor($quote['difference']);

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
     * Move the service to its new product, once. Returns false when the change was not waiting.
     */
    public function apply(PlanChange $change): bool
    {
        $credit = 0;

        $applied = DB::transaction(function () use ($change, &$credit): bool {
            $locked = PlanChange::query()->lockForUpdate()->find($change->id);

            if ($locked === null || ! $locked->isPending()) {
                return false;
            }

            $service = Service::query()->lockForUpdate()->findOrFail($locked->service_id);
            $service->update(['product_id' => $locked->to_product_id, 'recurring_amount' => $locked->new_amount]);
            $locked->update(['status' => PlanChange::STATUS_APPLIED, 'applied_at' => now()]);

            $service->loadMissing('client');

            if ($locked->mode === PlanChange::MODE_NOW && $locked->difference < 0 && $this->wallet->enabled() && $service->client->currency === $locked->currency) {
                $credit = -$locked->difference;
                $this->wallet->change($service->client, $credit, __('Unused time of your old plan for :service', ['service' => $service->domain ?: '#'.$service->id]));
            }

            return true;
        });

        if (! $applied) {
            return false;
        }

        $change->refresh()->load('service.client', 'service.product', 'fromProduct', 'toProduct');
        $service = $change->service;

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
                'cycle' => $change->billing_cycle->label(),
                'note' => $credit > 0 ? __('We added :amount to your wallet for the unused time of your old plan.', ['amount' => money($credit, $change->currency)]) : '',
            ],
        ]);

        return true;
    }

    /**
     * Apply the changes waiting for this invoice, now that it is paid.
     */
    public function applyForInvoice(Invoice $invoice): void
    {
        PlanChange::query()->where('invoice_id', $invoice->id)->where('status', PlanChange::STATUS_PENDING)->get()
            ->each(fn (PlanChange $change): bool => $this->apply($change));
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

        $change->update(['status' => PlanChange::STATUS_CANCELLED]);

        if ($change->invoice !== null && $change->invoice->status === InvoiceStatus::Unpaid) {
            $this->invoices->cancel($change->invoice);
        }

        Activity::log('service.plan_change_cancelled', "Plan change for service #{$change->service_id} stopped", $change->service);
    }

    /**
     * Apply the downgrades planned for renewal dates up to today. Returns how many were applied.
     */
    public function applyScheduled(?CarbonInterface $today = null): int
    {
        $today = CarbonImmutable::instance($today ?? today());

        return PlanChange::query()
            ->where('status', PlanChange::STATUS_PENDING)
            ->where('mode', PlanChange::MODE_RENEWAL)
            ->whereDate('apply_on', '<=', $today)
            ->get()
            ->filter(fn (PlanChange $change): bool => $this->apply($change))
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
