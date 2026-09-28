<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\HealthRun;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $currency = (string) setting('billing.currency');
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $unpaid = Invoice::query()->where('status', InvoiceStatus::Unpaid)->where('currency', $currency);

        return view('admin.dashboard', [
            'currency' => $currency,
            'revenueThisMonth' => (int) Transaction::query()
                ->whereIn('type', ['payment', 'refund'])
                ->where('currency', $currency)
                ->where('paid_at', '>=', $monthStart)
                ->sum('amount'),
            'revenueLastMonth' => (int) Transaction::query()
                ->whereIn('type', ['payment', 'refund'])
                ->where('currency', $currency)
                ->whereBetween('paid_at', [$monthStart->subMonth(), $monthStart])
                ->sum('amount'),
            'activeServices' => Service::query()->where('status', ServiceStatus::Active)->count(),
            'newServicesThisMonth' => Service::query()->where('created_at', '>=', $monthStart)->count(),
            'unpaidTotal' => (int) (clone $unpaid)->selectRaw('coalesce(sum(total - amount_paid), 0) as balance')->value('balance'),
            'unpaidCount' => (clone $unpaid)->count(),
            'overdueCount' => (clone $unpaid)->whereDate('due_at', '<', today())->count(),
            'openTickets' => Ticket::query()->whereIn('status', [TicketStatus::Open, TicketStatus::CustomerReply])->count(),
            'chart' => $this->revenueChart($currency),
            'attention' => $this->attentionItems(),
            'activity' => ActivityLog::query()->with('actor')->latest('id')->limit(8)->get(),
            'recentOrders' => Order::query()->with('client')->latest('id')->limit(5)->get(),
            'recentTickets' => Ticket::query()
                ->with(['client', 'department'])
                ->where('status', '!=', TicketStatus::Closed)
                ->latest('last_reply_at')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Twelve monthly revenue bars, scaled to a rounded maximum.
     *
     * @return array{bars: list<array{label: string, value: int, height: float}>, max: int}
     */
    private function revenueChart(string $currency): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        $totals = Transaction::query()
            ->whereIn('type', ['payment', 'refund'])
            ->where('currency', $currency)
            ->where('paid_at', '>=', $start)
            ->get(['amount', 'paid_at'])
            ->groupBy(fn (Transaction $transaction): string => $transaction->paid_at->format('Y-m'))
            ->map(fn ($group): int => (int) $group->sum('amount'));

        $max = $this->niceCeiling((int) $totals->max());

        $bars = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $start->addMonths($i);
            $value = $totals->get($month->format('Y-m'), 0);
            $bars[] = [
                'label' => $month->translatedFormat('M'),
                'value' => $value,
                'height' => $max > 0 ? max(0, round($value / $max * 100, 2)) : 0,
            ];
        }

        return ['bars' => $bars, 'max' => $max];
    }

    private function niceCeiling(int $value): int
    {
        if ($value <= 0) {
            return 0;
        }

        $magnitude = 10 ** (int) floor(log10($value));

        foreach ([1, 2, 5, 10] as $step) {
            if ($step * $magnitude >= $value) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /**
     * Things staff should act on today.
     *
     * @return list<array{tone: string, text: string, url: string|null, action: string|null}>
     */
    private function attentionItems(): array
    {
        $items = [];

        $lastRun = setting('automation.last_run_at');

        if (setting('automation.enabled') && ($lastRun === null || Carbon::parse($lastRun)->lt(now()->subHours(26)))) {
            $items[] = [
                'tone' => 'crit',
                'text' => __('The daily automation has not run in the last 24 hours. Renewal invoices and suspensions need the cron job.'),
                'url' => route('admin.settings.edit').'#automation',
                'action' => __('How to set it up'),
            ];
        }

        $health = rescue(fn () => HealthRun::query()->latest('id')->first(['id', 'urgent_count']), null, report: false);

        if ($health !== null && $health->urgent_count > 0 && auth('admin')->user()?->hasPermission('security.manage')) {
            $items[] = [
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
                'tone' => 'crit',
                'text' => trans_choice(':count paid domain is still waiting to be registered.|:count paid domains are still waiting to be registered.', $waitingDomains, ['count' => $waitingDomains]),
                'url' => route('admin.domains.index', ['status' => 'pending']),
                'action' => __('Review'),
            ];
        }

        $toReview = Order::query()->where('status', OrderStatus::Pending)->where('needs_review', true)->count();

        if ($toReview > 0) {
            $items[] = [
                'tone' => 'warn',
                'text' => trans_choice(':count order looks risky and needs your review.|:count orders look risky and need your review.', $toReview, ['count' => $toReview]),
                'url' => route('admin.orders.index', ['status' => 'pending']),
                'action' => __('Review'),
            ];
        }

        $pendingOrders = Order::query()->where('status', OrderStatus::Pending)->count();

        if ($pendingOrders > 0) {
            $items[] = [
                'tone' => 'warn',
                'text' => trans_choice(':count order is waiting for payment or review.|:count orders are waiting for payment or review.', $pendingOrders, ['count' => $pendingOrders]),
                'url' => route('admin.orders.index', ['status' => 'pending']),
                'action' => __('Open orders'),
            ];
        }

        $overdue = Invoice::query()->where('status', InvoiceStatus::Unpaid)->whereDate('due_at', '<', today()->subDays(7))->count();

        if ($overdue > 0) {
            $items[] = [
                'tone' => 'warn',
                'text' => trans_choice(':count invoice is more than 7 days overdue.|:count invoices are more than 7 days overdue.', $overdue, ['count' => $overdue]),
                'url' => route('admin.invoices.index', ['status' => 'overdue']),
                'action' => __('View'),
            ];
        }

        return $items;
    }
}
