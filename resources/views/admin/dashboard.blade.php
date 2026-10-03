<x-layouts.admin :title="__('Dashboard')">
    @php
        // Revenue and unpaid totals need billing.view, the site-wide activity needs settings.manage.
        $change = $canBilling && $revenueLastMonth > 0 ? round(($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth * 100, 1) : null;
        $admin = auth('admin')->user();
    @endphp

    <div class="page-head">
        <div>
            <h1>{{ __('Dashboard') }}</h1>
            <p>{{ __('Hello, :name', ['name' => \Illuminate\Support\Str::before($admin->name, ' ')]) }} · {{ now()->translatedFormat('l, j F Y') }}</p>
        </div>
    </div>

    <div class="kpis">
        @if ($canBilling)
            <div class="kpi">
                <small>{{ __('Revenue · :month', ['month' => now()->translatedFormat('F')]) }}</small>
                <b>{{ money($revenueThisMonth, $currency) }}</b>
                @if ($change !== null)
                    <span style="color:var({{ $change >= 0 ? '--nb-good' : '--nb-crit' }});font-weight:700">{{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}% {{ __('vs last month') }}</span>
                @else
                    <span>{{ __('No payments last month') }}</span>
                @endif
            </div>
        @endif
        <div class="kpi">
            <small>{{ __('Active services') }}</small>
            <b>{{ number_format($activeServices) }}</b>
            <span>{{ trans_choice(':count new this month|:count new this month', $newServicesThisMonth, ['count' => $newServicesThisMonth]) }}</span>
        </div>
        @if ($canBilling)
            <div class="kpi">
                <small>{{ __('Unpaid invoices') }}</small>
                <b>{{ money($unpaidTotal, $currency) }}</b>
                <span @if ($overdueCount) style="color:var(--nb-crit);font-weight:700" @endif>{{ trans_choice(':count invoice|:count invoices', $unpaidCount, ['count' => $unpaidCount]) }} · {{ __(':count overdue', ['count' => $overdueCount]) }}</span>
            </div>
        @endif
        <div class="kpi">
            <small>{{ __('Tickets waiting for a reply') }}</small>
            <b>{{ $openTickets }}</b>
            <span>{{ $openTickets ? __('Clients are waiting') : __('All caught up') }}</span>
        </div>
    </div>

    <div @class(['grid-2' => $canBilling])>
        @if ($canBilling)
            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Monthly revenue') }}</h2><span class="pill">{{ __('Last 12 months') }} · {{ $currency }}</span></div>
                <div style="padding:1.1rem">
                    @include('admin.partials.revenue-chart', ['chart' => $chart, 'currency' => $currency])
                </div>
            </section>
        @endif

        <section class="card card-flush">
            <div class="card-header"><h2>{{ __('Needs your attention') }}</h2></div>
            @forelse ($attention as $item)
                <div class="attention" data-tone="{{ $item['tone'] }}">
                    <span>{{ $item['text'] }}</span>
                    @if ($item['url'])
                        <a class="btn btn-sm" href="{{ $item['url'] }}">{{ $item['action'] }}</a>
                    @endif
                </div>
            @empty
                <div class="attention" data-tone="good"><span>{{ __('Nothing needs you right now.') }}</span></div>
            @endforelse
        </section>
    </div>

    <div class="grid-halves">
        @if ($admin->hasPermission('orders.manage'))
            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Recent orders') }}</h2><a class="btn btn-sm" href="{{ route('admin.orders.index') }}">{{ __('All orders') }}</a></div>
                @if ($recentOrders->isEmpty())
                    <div class="empty"><strong>{{ __('No orders yet') }}</strong>{{ __('New orders from your store appear here.') }}</div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Order') }}</th><th>{{ __('Client') }}</th><th class="end">{{ __('Total') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                        @foreach ($recentOrders as $order)
                            <tr>
                                <td><a class="row-link mono" href="{{ route('admin.orders.show', $order) }}">#{{ $order->number }}</a></td>
                                <td>{{ $order->client?->name ?? '—' }}</td>
                                <td class="end num">{{ money($order->total, $order->currency) }}</td>
                                <td><x-status :value="$order->status" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>
        @endif

        @if ($admin->hasPermission('support.manage'))
            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Support tickets') }}</h2><a class="btn btn-sm" href="{{ route('admin.tickets.index') }}">{{ __('All tickets') }}</a></div>
                @if ($recentTickets->isEmpty())
                    <div class="empty"><strong>{{ __('No open tickets') }}</strong>{{ __('All caught up.') }}</div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Subject') }}</th><th>{{ __('Department') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                        @foreach ($recentTickets as $ticket)
                            <tr>
                                <td>
                                    <a class="row-link" href="{{ route('admin.tickets.show', $ticket) }}">{{ $ticket->subject }}</a>
                                    <div class="faint" style="font-size:.8rem">{{ $ticket->client?->name }}@if ($ticket->last_reply_at) · {{ $ticket->last_reply_at->diffForHumans(short: true) }}@endif</div>
                                </td>
                                <td>{{ $ticket->department?->name }}</td>
                                <td><x-status :value="$ticket->status" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>
        @endif
    </div>

    @if ($admin->hasPermission('settings.manage'))
        <section class="card card-flush">
            <div class="card-header">
                <h2>{{ __('Recent activity') }}</h2>
                <a class="btn btn-sm" href="{{ route('admin.settings.activity') }}">{{ __('All activity') }}</a>
            </div>
            <ul class="list-plain" style="padding:0 1.1rem">
                @forelse ($activity as $entry)
                    <li class="feed-item">
                        <span>{{ $entry->description }} <span class="faint">· {{ $entry->actorName() }}</span></span>
                        <time datetime="{{ $entry->created_at->toIso8601String() }}" title="{{ $entry->created_at->translatedFormat('d M Y H:i') }}">{{ $entry->created_at->diffForHumans(short: true) }}</time>
                    </li>
                @empty
                    <li class="muted" style="padding:1rem 0">{{ __('Nothing has happened yet.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif
</x-layouts.admin>
