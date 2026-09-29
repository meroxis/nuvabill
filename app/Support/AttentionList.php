<?php

namespace App\Support;

use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
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
     * Things staff should act on today.
     *
     * @return list<array{key: string, tone: string, text: string, url: string|null, action: string|null}>
     */
    public static function items(): array
    {
        $items = [];

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

        if ($health !== null && $health->urgent_count > 0 && auth('admin')->user()?->hasPermission('security.manage')) {
            $items[] = [
                'key' => 'health',
                'tone' => 'crit',
                'text' => trans_choice('Site health found :count urgent security issue.|Site health found :count urgent security issues.', $health->urgent_count, ['count' => $health->urgent_count]),
                'url' => route('admin.health.index'),
                'action' => __('Open site health'),
            ];
        }

        $stuck = Service::query()
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

        $waitingDomains = Domain::query()
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

        $toReview = Order::query()->where('status', OrderStatus::Pending)->where('needs_review', true)->count();

        if ($toReview > 0) {
            $items[] = [
                'key' => 'orders.review',
                'tone' => 'warn',
                'text' => trans_choice(':count order looks risky and needs your review.|:count orders look risky and need your review.', $toReview, ['count' => $toReview]),
                'url' => route('admin.orders.index', ['status' => 'pending']),
                'action' => __('Review'),
            ];
        }

        $pendingOrders = Order::query()->where('status', OrderStatus::Pending)->count();

        if ($pendingOrders > 0) {
            $items[] = [
                'key' => 'orders.pending',
                'tone' => 'warn',
                'text' => trans_choice(':count order is waiting for payment or review.|:count orders are waiting for payment or review.', $pendingOrders, ['count' => $pendingOrders]),
                'url' => route('admin.orders.index', ['status' => 'pending']),
                'action' => __('Open orders'),
            ];
        }

        $overdue = Invoice::query()->where('status', InvoiceStatus::Unpaid)->whereDate('due_at', '<', today()->subDays(7))->count();

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
