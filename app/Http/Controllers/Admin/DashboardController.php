<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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
        $currency = (string) setting('billing.currency');
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $unpaid = Invoice::query()->where('status', InvoiceStatus::Unpaid)->where('currency', $currency);

        return view('admin.dashboard', [
            'currency' => $currency,
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
            'activeServices' => Service::query()->where('status', ServiceStatus::Active)->count(),
            'newServicesThisMonth' => Service::query()->where('created_at', '>=', $monthStart)->count(),
            'unpaidTotal' => (int) (clone $unpaid)->selectRaw('coalesce(sum(total - amount_paid), 0) as balance')->value('balance'),
            'unpaidCount' => (clone $unpaid)->count(),
            'overdueCount' => (clone $unpaid)->whereDate('due_at', '<', today())->count(),
            'openTickets' => Ticket::query()->whereIn('status', [TicketStatus::Open, TicketStatus::CustomerReply])->count(),
            'chart' => $this->revenueChart($currency),
            'attention' => AttentionList::items(),
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
            ->revenue()
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
}
