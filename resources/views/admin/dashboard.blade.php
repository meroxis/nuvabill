<x-layouts.admin :title="__('Dashboard')">
    @php
        $change = $revenueLastMonth > 0 ? round(($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth * 100, 1) : null;
        $admin = auth('admin')->user();
    @endphp

    <div class="page-head">
        <div>
            <h1>{{ __('Hello, :name', ['name' => \Illuminate\Support\Str::before($admin->name, ' ')]) }}</h1>
            <p>{{ now()->translatedFormat('l, j F Y') }}</p>
        </div>
    </div>

    <div class="kpis">
        <div class="kpi">
            <small>{{ __('Revenue · :month', ['month' => now()->translatedFormat('F')]) }}</small>
            <b>{{ money($revenueThisMonth, $currency) }}</b>
            @if ($change !== null)
                <span style="color:var({{ $change >= 0 ? '--nb-good' : '--nb-crit' }});font-weight:700">{{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}% {{ __('vs last month') }}</span>
            @else
                <span>{{ __('No payments last month') }}</span>
            @endif
        </div>
        <div class="kpi">
            <small>{{ __('Active services') }}</small>
            <b>{{ number_format($activeServices) }}</b>
            <span>{{ trans_choice(':count new this month|:count new this month', $newServicesThisMonth, ['count' => $newServicesThisMonth]) }}</span>
        </div>
        <div class="kpi">
            <small>{{ __('Unpaid invoices') }}</small>
            <b>{{ money($unpaidTotal, $currency) }}</b>
            <span @if ($overdueCount) style="color:var(--nb-crit);font-weight:700" @endif>{{ trans_choice(':count invoice|:count invoices', $unpaidCount, ['count' => $unpaidCount]) }} · {{ __(':count overdue', ['count' => $overdueCount]) }}</span>
        </div>
        <div class="kpi">
            <small>{{ __('Tickets waiting for a reply') }}</small>
            <b>{{ $openTickets }}</b>
            <span>{{ $openTickets ? __('Clients are waiting') : __('All caught up') }}</span>
        </div>
    </div>

    <div class="grid-2">
        <div style="display:grid;gap:14px;align-content:start;min-width:0">
            <section class="card">
                <div class="card-header"><h2>{{ __('Monthly revenue') }}</h2><span class="pill">{{ __('Last 12 months') }} · {{ $currency }}</span></div>
                @include('admin.partials.revenue-chart', ['chart' => $chart, 'currency' => $currency])
            </section>

            <section class="card">
                <div class="card-header"><h2>{{ __('Needs your attention') }}</h2></div>
                @forelse ($attention as $item)
                    <div class="attention" data-tone="{{ $item['tone'] }}" @if (! $loop->last) style="margin-bottom:8px" @endif>
                        <span>{{ $item['text'] }}</span>
                        @if ($item['url'])
                            <a class="btn btn-sm" href="{{ $item['url'] }}">{{ $item['action'] }}</a>
                        @endif
                    </div>
                @empty
                    <p class="muted" style="margin:0">{{ __('Nothing needs you right now.') }}</p>
                @endforelse
            </section>
        </div>

        <section class="card">
            <div class="card-header">
                <h2>{{ __('Recent activity') }}</h2>
                @if ($admin->hasPermission('settings.manage'))
                    <a class="btn btn-sm" href="{{ route('admin.settings.activity') }}">{{ __('All activity') }}</a>
                @endif
            </div>
            <ul class="list-plain">
                @forelse ($activity as $entry)
                    <li class="feed-item">
                        <span>{{ $entry->description }} <span class="faint">· {{ $entry->actorName() }}</span></span>
                        <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->format('d M Y H:i') }}">{{ $entry->created_at->diffForHumans(short: true) }}</time>
                    </li>
                @empty
                    <li class="muted">{{ __('Nothing has happened yet.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-layouts.admin>
