@extends('theme::layouts.app')

@section('title', $service->domain ?: $service->product->name)

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><bdi>{{ $service->product->name }}</bdi></p>
            <h1 style="margin-top:.3rem">{{ $service->domain ?: $service->product->name }} <x-status :value="$service->status" style="vertical-align:middle" /></h1>
        </div>
        @if ($canLogin && $service->status === \App\Enums\ServiceStatus::Active)
            <form method="POST" action="{{ route('client.services.login', $service) }}" target="_blank">
                @csrf
                <button class="btn btn-primary" type="submit"><x-icon name="external" />{{ __('Open control panel') }}</button>
            </form>
        @endif
    </div>

    @if ($service->status === \App\Enums\ServiceStatus::Suspended)
        <div class="flash" data-tone="warn"><span>{{ __('This service is suspended. Pay any open invoice to switch it back on, or contact support.') }}</span></div>
    @elseif ($service->status === \App\Enums\ServiceStatus::Pending)
        <div class="flash" data-tone="info"><span>{{ __('This service is being set up. It starts as soon as the first invoice is paid.') }}</span></div>
    @endif

    @if ($panel)
        @include($panel['view'], ['service' => $service, 'panel' => $panel['data'], 'actions' => $panel['actions'], 'result' => session('panel_result')])
    @endif

    @foreach (app(\App\Support\ServicePanels::class)->for($service) as $extra)
        @include($extra['view'], $extra['data'] + ['service' => $service])
    @endforeach

    <div class="two-col">
        <section class="card" style="display:grid;gap:1rem">
            <h2 style="font-size:1.05rem">{{ __('Details') }}</h2>
            <dl class="dl">
                <dt>{{ __('Plan') }}</dt><dd><bdi>{{ $service->product->name }}</bdi></dd>
                @if ($service->domain)<dt>{{ __('Domain') }}</dt><dd>{{ $service->domain }}</dd>@endif
                @if ($service->username)<dt>{{ __('Username') }}</dt><dd class="mono">{{ $service->username }}</dd>@endif
                @if ($service->password)
                    <dt>{{ __('Password') }}</dt>
                    <dd x-data="{ show: false }">
                        <span class="mono" x-show="show" x-cloak>{{ $service->password }}</span>
                        <span x-show="! show">••••••••</span>
                        <button type="button" class="btn btn-sm" @click="show = ! show" x-text="show ? @js(__('Hide')) : @js(__('Show'))" style="margin-inline-start:.4rem"></button>
                    </dd>
                @endif
                @if ($service->server)
                    <dt>{{ __('Server') }}</dt><dd class="mono">{{ $service->server->hostname }}</dd>
                    @if ($service->server->nameservers)
                        <dt>{{ __('Nameservers') }}</dt><dd class="mono">{!! collect($service->server->nameservers)->map(fn ($ns) => e($ns))->implode('<br>') !!}</dd>
                    @endif
                @endif
                <dt>{{ __('Billing') }}</dt><dd>{{ money($service->recurring_amount ?: $service->first_payment_amount, $service->currency) }} · {{ $service->billing_cycle->label() }}</dd>
                <dt>{{ __('Next payment') }}</dt><dd>{{ $service->next_due_date?->translatedFormat('d M Y') ?? '—' }}</dd>
                <dt>{{ __('Since') }}</dt><dd>{{ $service->registration_date->translatedFormat('d M Y') }}</dd>
                @foreach ($service->addons->where('status', \App\Models\ServiceAddon::STATUS_ACTIVE) as $addon)
                    <dt>{{ __('Add-on') }}</dt><dd>{{ $addon->name }}@if ($addon->recurring_amount) · {{ money($addon->recurring_amount, $service->currency) }}@endif</dd>
                @endforeach
                @if ($service->coupon)
                    <dt>{{ __('Coupon') }}</dt><dd><span class="mono">{{ $service->coupon->code }}</span> · {{ $service->coupon->describe() }}@if ($service->coupon_payments_left !== null) · {{ trans_choice(':count more payment|:count more payments', $service->coupon_payments_left, ['count' => $service->coupon_payments_left]) }}@endif</dd>
                @endif
            </dl>
            @if (! empty($pendingChange))
                <p class="muted" style="margin:0">{{ $pendingChange->mode === \App\Models\PlanChange::MODE_RENEWAL ? __('Your plan changes to :plan on :date.', ['plan' => $pendingChange->toProduct?->name, 'date' => $pendingChange->apply_on->translatedFormat('d M Y')]) : __('A move to :plan is waiting for payment.', ['plan' => $pendingChange->toProduct?->name]) }} <a href="{{ route('client.services.change-plan', $service) }}">{{ __('Details') }}</a></p>
            @elseif (! empty($canChangePlan))
                <div><a class="btn btn-sm" href="{{ route('client.services.change-plan', $service) }}"><x-icon name="refresh" />{{ __('Upgrade or downgrade') }}</a></div>
            @endif
        </section>

        <aside class="card" style="display:grid;gap:.8rem">
            <h2 style="font-size:1.05rem">{{ __('Invoices') }}</h2>
            @forelse ($service->invoiceItems->sortByDesc('id')->take(6) as $item)
                <div class="summary-row">
                    <a class="mono" href="{{ route('client.invoices.show', $item->invoice) }}">{{ $item->invoice->displayNumber() }}</a>
                    <x-status :value="$item->invoice->status" />
                </div>
            @empty
                <p class="muted" style="margin:0">{{ __('No invoices yet.') }}</p>
            @endforelse
            <a class="btn btn-sm" href="{{ route('client.tickets.create', ['service' => $service->id]) }}">{{ __('Get help with this service') }}</a>
        </aside>
    </div>
@endsection
