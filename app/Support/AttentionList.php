<?php

namespace App\Support;

use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MachineStatus;
use App\Enums\OrderStatus;
use App\Enums\PowerState;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Domain;
use App\Models\HealthRun;
use App\Models\Invoice;
use App\Models\Machine;
use App\Models\Order;
use App\Models\ResourceChange;
use App\Models\Service;
use Illuminate\Support\Carbon;

/**
 * What staff should act on today, shown on the dashboard and on the phone app's Today screen.
 */
final class AttentionList
{
    /**
     * Things staff should act on today. An item is only for staff who may open the page it links to,
     * so a support role does not see the counts of orders or overdue invoices.
     *
     * @return list<array{key: string, tone: string, text: string, url: string|null, action: string|null}>
     */
    public static function items(): array
    {
        $items = [];
        $admin = auth('admin')->user();
        $may = fn (string $permission): bool => (bool) $admin?->hasPermission($permission);

        $lastRun = setting('automation.last_run_at');

        if (setting('automation.enabled') && ($lastRun === null || Carbon::parse($lastRun)->lt(now()->subHours(26)))) {
            $items[] = [
                'key' => 'automation',
                'tone' => 'crit',
                'text' => __('The daily automation has not run in the last 24 hours. Renewal invoices and suspensions need the cron job.'),
                'url' => route('admin.settings.edit').'#automation',
                'action' => __('How to set it up'),
            ];
        }

        $health = rescue(fn () => HealthRun::query()->latest('id')->first(['id', 'urgent_count']), null, report: false);

        if ($health !== null && $health->urgent_count > 0 && $may('security.manage')) {
            $items[] = [
                'key' => 'health',
                'tone' => 'crit',
                'text' => trans_choice('Site health found :count urgent security issue.|Site health found :count urgent security issues.', $health->urgent_count, ['count' => $health->urgent_count]),
                'url' => route('admin.health.index'),
                'action' => __('Open site health'),
            ];
        }

        $paidPending = fn () => Service::query()
            ->where('status', ServiceStatus::Pending)
            ->whereHas('invoiceItems.invoice', fn ($query) => $query->where('status', InvoiceStatus::Paid));

        // A dedicated server whose machine waits for the OS install is listed on its own below. While
        // an update has not made the hardware tables yet, every paid pending service counts.
        $stuck = ! $may('services.manage') ? 0 : (int) rescue(
            fn (): int => $paidPending()->whereDoesntHave('machine', fn ($query) => $query->where('status', MachineStatus::Provisioning->value))->count(),
            fn (): int => $paidPending()->count(),
            report: false,
        );

        if ($stuck > 0) {
            $items[] = [
                'key' => 'services',
                'tone' => 'crit',
                'text' => trans_choice(':count paid service is still waiting to be set up.|:count paid services are still waiting to be set up.', $stuck, ['count' => $stuck]),
                'url' => route('admin.services.index', ['status' => 'pending']),
                'action' => __('Review'),
            ];
        }

        array_push($items, ...self::hardware($may));
        array_push($items, ...self::resourceChanges($may));

        if ($admin instanceof Admin) {
            array_push($items, ...app(AttentionItems::class)->for($admin));
        }

        $waitingDomains = ! $may('domains.manage') ? 0 : Domain::query()
            ->where('status', DomainStatus::Pending)
            ->whereHas('invoiceItems.invoice', fn ($query) => $query->where('status', InvoiceStatus::Paid))
            ->count();

        if ($waitingDomains > 0) {
            $items[] = [
                'key' => 'domains',
                'tone' => 'crit',
                'text' => trans_choice(':count paid domain is still waiting to be registered.|:count paid domains are still waiting to be registered.', $waitingDomains, ['count' => $waitingDomains]),
                'url' => route('admin.domains.index', ['status' => 'pending']),
                'action' => __('Review'),
            ];
        }

        // A gateway said these invoices were paid, but the payment was held because it may be a
        // test payment. Each one stays here until the invoice is paid, for at most 30 days.
        $toCheck = ! $may('billing.view') ? [] : ActivityLog::query()
            ->where('action', ActivityLog::PAYMENT_REVIEW)
            ->where('subject_type', (new Invoice)->getMorphClass())
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('subject_id', Invoice::query()->select('id')->where('status', InvoiceStatus::Unpaid))
            ->latest('id')
            ->pluck('subject_id')
            ->unique()
            ->values()
            ->all();

        if ($toCheck !== []) {
            $items[] = [
                'key' => 'payments.review',
                'tone' => 'crit',
                'text' => trans_choice(':count payment was not counted because it may be a test payment. Check in the gateway that the money arrived, then add it by hand.|:count payments were not counted because they may be test payments. Check in the gateway that the money arrived, then add them by hand.', count($toCheck), ['count' => count($toCheck)]),
                'url' => route('admin.invoices.show', $toCheck[0]),
                'action' => __('Review'),
            ];
        }

        // A gateway said money of these invoices went back without this site sending it: a refund
        // made at the payment service, or a dispute. Each one stays here until a credit note covers
        // the money, for at most 30 days.
        $reversedInvoices = fn (array $statuses): array => ! $may('billing.view') ? [] : Invoice::query()
            ->whereIn('status', $statuses)
            ->whereIn('id', ActivityLog::query()
                ->select('subject_id')
                ->where('action', ActivityLog::PAYMENT_REVERSED)
                ->where('subject_type', (new Invoice)->getMorphClass())
                ->where('created_at', '>=', now()->subDays(30)))
            ->with('creditNotes')
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->returnedWithoutCreditNote() > 0)
            ->pluck('id')
            ->all();
        $reversed = $reversedInvoices([InvoiceStatus::Paid]);
        // The same on an invoice whose money was already given back here (cancelled, or credited
        // to the wallet): no credit note can settle it, so it stays for the 30 days.
        $reversedAfterReturn = $reversedInvoices([InvoiceStatus::Cancelled, InvoiceStatus::Refunded]);

        if ($reversedAfterReturn !== []) {
            $items[] = [
                'key' => 'payments.reversed_after_return',
                'tone' => 'crit',
                'text' => trans_choice(':count payment went back at the payment service after its money was already given back here. Check the client\'s wallet.|:count payments went back at the payment service after their money was already given back here. Check the clients\' wallets.', count($reversedAfterReturn), ['count' => count($reversedAfterReturn)]),
                'url' => route('admin.invoices.show', $reversedAfterReturn[0]),
                'action' => __('Review'),
            ];
        }

        if ($reversed !== []) {
            $items[] = [
                'key' => 'payments.reversed',
                'tone' => 'crit',
                'text' => trans_choice(':count payment was refunded or disputed at the payment service. Make a credit note for it, and check the client\'s services and wallet.|:count payments were refunded or disputed at the payment service. Make a credit note for each, and check the clients\' services and wallets.', count($reversed), ['count' => count($reversed)]),
                'url' => route('admin.invoices.show', $reversed[0]),
                'action' => __('Review'),
            ];
        }

        $mayOrders = $may('orders.manage');
        $toReview = ! $mayOrders ? 0 : Order::query()->where('status', OrderStatus::Pending)->where('needs_review', true)->count();

        if ($toReview > 0) {
            $items[] = [
                'key' => 'orders.review',
                'tone' => 'warn',
                'text' => trans_choice(':count order looks risky and needs your review.|:count orders look risky and need your review.', $toReview, ['count' => $toReview]),
                'url' => route('admin.orders.index', ['status' => 'pending']),
                'action' => __('Review'),
            ];
        }

        $pendingOrders = ! $mayOrders ? 0 : Order::query()->where('status', OrderStatus::Pending)->count();

        if ($pendingOrders > 0) {
            $items[] = [
                'key' => 'orders.pending',
                'tone' => 'warn',
                'text' => trans_choice(':count order is waiting for payment or review.|:count orders are waiting for payment or review.', $pendingOrders, ['count' => $pendingOrders]),
                'url' => route('admin.orders.index', ['status' => 'pending']),
                'action' => __('Open orders'),
            ];
        }

        $overdue = ! $may('billing.view') ? 0 : Invoice::query()->where('status', InvoiceStatus::Unpaid)->whereDate('due_at', '<', today()->subDays(7))->count();

        if ($overdue > 0) {
            $items[] = [
                'key' => 'invoices.overdue',
                'tone' => 'warn',
                'text' => trans_choice(':count invoice is more than 7 days overdue.|:count invoices are more than 7 days overdue.', $overdue, ['count' => $overdue]),
                'url' => route('admin.invoices.index', ['status' => 'overdue']),
                'action' => __('View'),
            ];
        }

        return $items;
    }

    /**
     * Dedicated servers in your own rack: OS installs to do (services.manage), machines that wait
     * for a disk wipe (hardware.manage) and suspended machines that are still on (hardware.power).
     *
     * @param  callable(string): bool  $may
     * @return list<array{key: string, tone: string, text: string, url: string|null, action: string|null}>
     */
    private static function hardware(callable $may): array
    {
        $mayServices = $may('services.manage');
        $mayPower = $may('hardware.power');
        $mayManage = $may('hardware.manage');

        if (! $mayServices && ! $mayPower && ! $mayManage) {
            return [];
        }

        // While an update has not made the hardware tables yet, there is nothing to list.
        return rescue(function () use ($mayServices, $mayPower, $mayManage): array {
            $items = [];
            $list = fn (MachineStatus $status): ?string => $mayPower ? route('admin.machines.index', ['status' => $status->value]) : null;

            $installs = ! $mayServices ? 0 : Machine::query()->where('status', MachineStatus::Provisioning->value)->whereNotNull('service_id')->count();

            if ($installs > 0) {
                $items[] = [
                    'key' => 'hardware.install',
                    'tone' => 'warn',
                    'text' => trans_choice(':count dedicated server waits for an OS install.|:count dedicated servers wait for an OS install.', $installs, ['count' => $installs]),
                    'url' => $list(MachineStatus::Provisioning) ?? route('admin.services.index', ['status' => ServiceStatus::Pending->value]),
                    'action' => __('Review'),
                ];
            }

            $wipes = ! $mayManage ? 0 : Machine::query()->where('status', MachineStatus::Wiping->value)->count();

            if ($wipes > 0) {
                $items[] = [
                    'key' => 'hardware.wipe',
                    'tone' => 'warn',
                    'text' => trans_choice(':count machine waits for a disk wipe before it can be sold again.|:count machines wait for a disk wipe before they can be sold again.', $wipes, ['count' => $wipes]),
                    'url' => $list(MachineStatus::Wiping),
                    'action' => $mayPower ? __('Review') : null,
                ];
            }

            $stillOn = ! $mayPower ? 0 : Machine::query()->where('status', MachineStatus::Suspended->value)->where('power_state', PowerState::On->value)->count();

            if ($stillOn > 0) {
                $items[] = [
                    'key' => 'hardware.still_on',
                    'tone' => 'crit',
                    'text' => trans_choice(':count suspended machine is still on.|:count suspended machines are still on.', $stillOn, ['count' => $stillOn]),
                    'url' => $list(MachineStatus::Suspended),
                    'action' => __('Review'),
                ];
            }

            return $items;
        }, [], report: false);
    }

    /**
     * Changes of server resources (extras) that the server did not take, or that no module can
     * set, so staff must set them by hand or try again. Only for active and suspended services.
     *
     * @param  callable(string): bool  $may
     * @return list<array{key: string, tone: string, text: string, url: string|null, action: string|null}>
     */
    private static function resourceChanges(callable $may): array
    {
        if (! $may('services.manage')) {
            return [];
        }

        return rescue(function (): array {
            $waiting = fn () => ResourceChange::query()
                ->whereIn('server_state', [ResourceChange::SERVER_FAILED, ResourceChange::SERVER_MANUAL])
                ->where('status', '!=', ResourceChange::STATUS_CANCELLED)
                ->whereHas('service', fn ($query) => $query->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended]));
            $count = $waiting()->count();

            if ($count === 0) {
                return [];
            }

            // The services list has no filter for this, so the item opens the oldest one to fix.
            return [[
                'key' => 'resources.staff',
                'tone' => 'crit',
                'text' => trans_choice(':count resource change needs staff.|:count resource changes need staff.', $count, ['count' => $count]),
                'url' => route('admin.services.show', (int) $waiting()->orderBy('id')->value('service_id')),
                'action' => __('Review'),
            ]];
        }, [], report: false);
    }
}
