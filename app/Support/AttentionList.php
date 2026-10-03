<?php

namespace App\Support;

use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\HealthRun;
use App\Models\Invoice;
use App\Models\Order;
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

        $stuck = ! $may('services.manage') ? 0 : Service::query()
            ->where('status', ServiceStatus::Pending)
            ->whereHas('invoiceItems.invoice', fn ($query) => $query->where('status', InvoiceStatus::Paid))
            ->count();

        if ($stuck > 0) {
            $items[] = [
                'key' => 'services',
                'tone' => 'crit',
                'text' => trans_choice(':count paid service is still waiting to be set up.|:count paid services are still waiting to be set up.', $stuck, ['count' => $stuck]),
                'url' => route('admin.services.index', ['status' => 'pending']),
                'action' => __('Review'),
            ];
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
}
