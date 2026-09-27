@extends('theme::layouts.app')

@section('title', __('Developer account'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Developer account') }}</p>
            <h1 style="margin-top:.3rem">{{ $developer->name }} @if ($developer->is_verified || $developer->is_official)<x-pill tone="good">{{ __('Verified developer') }}</x-pill>@endif</h1>
        </div>
        <a class="btn btn-primary" href="{{ route('developer.items.create') }}"><x-icon name="plus" />{{ __('Submit a new item') }}</a>
    </div>

    <div class="dev-kpis">
        <div class="card"><small>{{ __('Earned in :month', ['month' => now()->translatedFormat('F')]) }}</small><b>{{ money($thisMonth, $currency) }}</b><span>{{ trans_choice(':count sale|:count sales', $salesThisMonth, ['count' => $salesThisMonth]) }}</span></div>
        <div class="card"><small>{{ __('Earned all time') }}</small><b>{{ money($allTime, $currency) }}</b><span>{{ __('Your share: :share%', ['share' => $developer->share()]) }}</span></div>
        <div class="card"><small>{{ __('Live items') }}</small><b>{{ $developer->items->filter->isLive()->count() }}</b><span>{{ trans_choice(':count install|:count installs', $developer->items->sum('installs_count'), ['count' => number_format($developer->items->sum('installs_count'))]) }}</span></div>
        <div class="card"><small>{{ __('Waiting for you') }}</small><b>{{ $waiting }}</b><span>{{ __('Changes asked by reviewers') }}</span></div>
    </div>

    <div class="two-col">
        <section class="card" style="display:grid;gap:.8rem">
            <div style="display:flex;justify-content:space-between;align-items:center"><h2 style="font-size:1.05rem">{{ __('Your earnings') }}</h2><span class="muted" style="font-size:.85rem">{{ __('Last 6 months') }}</span></div>
            @php $max = max(1, $chart->max('amount')); @endphp
            <div class="dev-chart" role="img" aria-label="{{ __('Earnings by month') }}: {{ $chart->map(fn ($m) => $m['label'].' '.money($m['amount'], $currency))->implode(', ') }}">
                @foreach ($chart as $month)
                    <div class="dev-bar"><span style="height:{{ max(3, round($month['amount'] / $max * 100)) }}%" title="{{ money($month['amount'], $currency) }}"></span><small>{{ $month['label'] }}</small></div>
                @endforeach
            </div>
        </section>

        <aside class="dev-payout">
            <small>{{ __('Next payout') }}</small>
            <b>{{ money($balance, $currency) }}</b>
            <span>{{ $developer->payout_method ? __('By :method', ['method' => $methods[$developer->payout_method] ?? $developer->payout_method]) : __('Add payout details below') }}</span>
            <div class="dev-split"><span style="width:{{ $developer->share() }}%"></span><span style="width:{{ 100 - $developer->share() }}%"></span></div>
            <div style="display:flex;justify-content:space-between;font-size:.85rem"><span><b>{{ $developer->share() }}%</b> {{ __('is yours') }}</span><span><b>{{ 100 - $developer->share() }}%</b> {{ __('marketplace fee') }}</span></div>
        </aside>
    </div>

    <div class="two-col">
        <section class="card card-flush">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Status') }}</th><th class="end">{{ __('Price') }}</th><th class="end">{{ __('Sales') }}</th></tr></thead>
                <tbody>
                @forelse ($developer->items as $item)
                    @php $last = $item->versions->first(); @endphp
                    <tr>
                        <td><a href="{{ route('developer.items.show', $item) }}"><b>{{ $item->name }}</b></a><div class="muted" style="font-size:.8rem">{{ $item->type->label() }}@if ($last) · v{{ $last->version }}@endif</div></td>
                        <td>@include('theme::developer.partials.status', ['version' => $last, 'item' => $item])</td>
                        <td class="end num">{{ $item->isFree() ? __('Free') : money($item->price, $item->currency) }}</td>
                        <td class="end num">{{ $item->isFree() ? number_format($item->installs_count).' '.__('installs') : number_format($item->sales_count) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="empty"><strong>{{ __('No items yet') }}</strong>{{ __('Submit your first theme or extension.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

        <div style="display:grid;gap:16px;align-content:start">
            @if ($latestMessage)
                <section class="card" style="display:grid;gap:.6rem">
                    <h2 style="font-size:1.05rem">{{ __('Latest review message') }}</h2>
                    <div class="muted" style="font-size:.85rem">{{ $latestMessage->version->item->name }} · {{ __('version :version', ['version' => $latestMessage->version->version]) }}</div>
                    <div class="dev-message">{{ $latestMessage->message }}</div>
                    <a class="btn btn-sm" href="{{ route('developer.items.show', $latestMessage->version->item) }}">{{ __('Open the item') }}</a>
                </section>
            @endif

            <form method="POST" action="{{ route('developer.profile') }}" class="card" style="display:grid;gap:.8rem">
                @csrf
                @method('PUT')
                <h2 style="font-size:1.05rem">{{ __('Profile and payouts') }}</h2>
                <x-input name="name" :label="__('Developer name')" :value="$developer->name" required />
                <x-input name="website" type="url" :label="__('Website')" :value="$developer->website" />
                <x-select name="payout_method" :label="__('How we pay you')" :options="$methods" :value="$developer->payout_method" :placeholder="__('Choose')" />
                <x-textarea name="payout_details" :label="__('Payout details')" rows="2" :help="$developer->payout_details ? __('Saved. Leave empty to keep them.') : __('Your Wayl or FIB number, or bank account.')" />
                <button class="btn" type="submit">{{ __('Save') }}</button>
            </form>
        </div>
    </div>
@endsection
