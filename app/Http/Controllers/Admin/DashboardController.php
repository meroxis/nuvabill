<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Support\AttentionList;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        /** @var Admin $admin */
        $admin = auth('admin')->user();
        $currency = (string) setting('billing.currency');
        $monthStart = CarbonImmutable::now()->startOfMonth();

        // Every staff member lands here, so each part is loaded only for staff who may see it,
        // with the same permission as the page it comes from.
        $billing = $admin->hasPermission('billing.view') ? $this->billing($currency, $monthStart) : [];

        return view('admin.dashboard', $billing + [
            'currency' => $currency,
            'canBilling' => $billing !== [],
            'activeServices' => Service::query()->where('status', ServiceStatus::Active)->count(),
            'newServicesThisMonth' => Service::query()->where('created_at', '>=', $monthStart)->count(),
            'openTickets' => Ticket::query()->whereIn('status', [TicketStatus::Open, TicketStatus::CustomerReply])->count(),
            'attention' => AttentionList::items(),
            'activity' => $admin->hasPermission('settings.manage')
                ? ActivityLog::query()->with('actor')->latest('id')->limit(8)->get()
                : collect(),
            'recentOrders' => $admin->hasPermission('orders.manage')
                ? Order::query()->with('client')->latest('id')->limit(5)->get()
                : collect(),
            'recentTickets' => $admin->hasPermission('support.manage')
                ? Ticket::query()
                    ->with(['client', 'department'])
                    ->where('status', '!=', TicketStatus::Closed)
                    ->latest('last_reply_at')
                    ->limit(5)
                    ->get()
                : collect(),
        ]);
    }

    /**
     * Revenue and unpaid invoices in the default currency.
     *
     * @return array{revenueThisMonth: int, revenueLastMonth: int, unpaidTotal: int, unpaidCount: int, overdueCount: int, chart: array{bars: list<array{label: string, value: int, height: float}>, max: int}}
     */
    private function billing(string $currency, CarbonImmutable $monthStart): array
    {
        $unpaid = Invoice::query()->where('status', InvoiceStatus::Unpaid)->where('currency', $currency);

        return [
            'revenueThisMonth' => (int) Transaction::query()
                ->revenue()
                ->where('currency', $currency)
                ->where('paid_at', '>=', $monthStart)
                ->sum('amount'),
            'revenueLastMonth' => (int) Transaction::query()
                ->revenue()
                ->where('currency', $currency)
                ->whereBetween('paid_at', [$monthStart->subMonth(), $monthStart])
                ->sum('amount'),
            'unpaidTotal' => (int) (clone $unpaid)->selectRaw('coalesce(sum(total - amount_paid), 0) as balance')->value('balance'),
            'unpaidCount' => (clone $unpaid)->count(),
            'overdueCount' => (clone $unpaid)->whereDate('due_at', '<', today())->count(),
            'chart' => $this->revenueChart($currency),
        ];
    }

    /**
     * Twelve monthly revenue bars, scaled to a rounded maximum.
     *
     * @return array{bars: list<array{label: string, value: int, height: float}>, max: int}
     */
    private function revenueChart(string $currency): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(11);

        // The database adds up each month ("2026-10" from "2026-10-03 12:00:00" in SQLite and MySQL),
        // so the dashboard does not load every payment of the year.
        $monthKey = 'substr(paid_at, 1, 7)';
        $totals = Transaction::query()
            ->revenue()
            ->where('currency', $currency)
            ->where('paid_at', '>=', $start)
            ->selectRaw("{$monthKey} as month, sum(amount) as total")
            ->groupByRaw($monthKey)
            ->pluck('total', 'month')
            ->map(fn (mixed $total): int => (int) $total);

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
}
