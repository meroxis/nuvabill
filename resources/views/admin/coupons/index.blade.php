<x-layouts.admin :title="__('Coupons')">
    <div class="page-head">
        <div>
            <h1>{{ __('Coupons') }}</h1>
            <p>{{ __('Discount codes for new orders and renewals. Share a code, or a link that applies it: :link', ['link' => route('store.index').'?coupon=CODE']) }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.coupons.create') }}"><x-icon name="plus" />{{ __('New coupon') }}</a>
    </div>

    <div class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <div class="kpi"><small>{{ __('Active coupons') }}</small><b>{{ $activeCount }}</b></div>
        <div class="kpi"><small>{{ __('Used this month') }}</small><b>{{ $usedThisMonth }}</b></div>
        <div class="kpi"><small>{{ __('Discount given this month') }}</small><b>{{ money($givenThisMonth, $currency) }}</b></div>
    </div>

    <form class="filters" method="GET" role="search">
        <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Find a code') }}" aria-label="{{ __('Find a coupon code') }}" style="max-width:260px">
    </form>

    <section class="card card-flush">
        @if ($coupons->isEmpty())
            <div class="empty"><strong>{{ __('No coupons yet') }}</strong>{{ __('Create a code like WELCOME20 to give new clients 20% off.') }}
                <div style="margin-top:1rem"><a class="btn btn-primary" href="{{ route('admin.coupons.create') }}">{{ __('Create a coupon') }}</a></div>
            </div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Discount') }}</th><th>{{ __('Applies to') }}</th><th class="end">{{ __('Used') }}</th><th>{{ __('Ends') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($coupons as $coupon)
                    <tr>
                        <td><a class="row-link mono" href="{{ route('admin.coupons.edit', $coupon) }}">{{ $coupon->code }}</a></td>
                        <td><b>{{ $coupon->describe() }}</b><div class="faint" style="font-size:.8rem">{{ $coupon->paymentsLabel() }}</div></td>
                        <td style="max-width:280px">
                            {{ empty($coupon->product_ids) ? __('All products') : collect($coupon->product_ids)->map(fn ($id) => $products[$id] ?? null)->filter()->implode(', ') }}@if ($coupon->applies_to_domains), {{ __('domains') }}@endif
                            @if (! empty($coupon->billing_cycles))<div class="faint" style="font-size:.8rem">{{ collect($coupon->billing_cycles)->map(fn ($cycle) => \App\Enums\BillingCycle::tryFrom($cycle)?->label())->filter()->implode(', ') }}</div>@endif
                        </td>
                        <td class="end num">{{ $coupon->uses }}@if ($coupon->max_uses) / {{ $coupon->max_uses }}@endif</td>
                        <td style="white-space:nowrap">{{ $coupon->ends_at?->format('d M Y') ?? __('No end') }}</td>
                        <td>
                            @if ($coupon->isExpired())<x-pill>{{ __('Ended') }}</x-pill>
                            @elseif ($coupon->isScheduled())<x-pill tone="info">{{ __('Scheduled') }}</x-pill>
                            @else<x-pill tone="good">{{ __('Active') }}</x-pill>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $coupons->links() }}
        @endif
    </section>
</x-layouts.admin>
