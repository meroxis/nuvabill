@extends('theme::layouts.app')

@section('title', __('Change plan'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('client.services.show', $service) }}"><bdi>{{ $service->domain ?: $service->product->name }}</bdi></a></p>
            <h1 style="margin-top:.3rem">{{ __('Change plan') }}</h1>
        </div>
    </div>

    @if ($pending)
        <div class="card" style="display:grid;gap:.8rem">
            @if ($pending->mode === \App\Models\PlanChange::MODE_RENEWAL)
                <p style="margin:0">{{ __('Your plan changes to :plan on :date.', ['plan' => $pending->toProduct?->name, 'date' => $pending->apply_on->translatedFormat('d M Y')]) }}</p>
            @elseif ($pending->invoice)
                <p style="margin:0">{{ __('Pay invoice :number and your plan changes to :plan straight away.', ['number' => $pending->invoice->displayNumber(), 'plan' => $pending->toProduct?->name]) }}</p>
            @endif
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @if ($pending->invoice && $pending->mode === \App\Models\PlanChange::MODE_INVOICE)
                    <a class="btn btn-primary btn-sm" href="{{ route('client.invoices.show', $pending->invoice) }}">{{ __('Pay the invoice') }}</a>
                @endif
                <form method="POST" action="{{ route('client.services.change-plan.destroy', $service) }}" data-confirm="{{ __('Stop this plan change?') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-ghost" type="submit">{{ __('Stop this change') }}</button>
                </form>
            </div>
        </div>
    @elseif ($blocked)
        <div class="flash" data-tone="warn"><span>{{ $blocked }}</span></div>
    @endif

    <section class="card" style="display:grid;gap:.6rem">
        <h2 style="font-size:1.05rem;margin:0">{{ __('Your plan now') }}</h2>
        <dl class="dl">
            <dt>{{ __('Plan') }}</dt><dd><bdi>{{ $service->product->name }}</bdi></dd>
            <dt>{{ __('Price') }}</dt><dd>{{ money($service->recurring_amount, $service->currency) }} · {{ $service->billing_cycle->label() }}</dd>
            <dt>{{ __('Next payment') }}</dt><dd>{{ $service->next_due_date?->translatedFormat('d M Y') ?? '—' }}</dd>
        </dl>
    </section>

    @if (! $blocked && ! $pending)
        @if ($options->isEmpty())
            <div class="card empty"><strong>{{ __('No other plans') }}</strong>{{ __('There are no plans this service can move to. Open a ticket if you need a change.') }}</div>
        @else
            <p class="muted" style="margin:0">{{ trans_choice('Prices are worked out for the :count day left until your next payment.|Prices are worked out for the :count days left until your next payment.', $options->first()['quote']['days_left'], ['count' => $options->first()['quote']['days_left']]) }}</p>
            <div class="plans">
                @foreach ($options as $option)
                    @php
                        $product = $option['product'];
                        $quote = $option['quote'];
                        $mode = $modeFor($quote['difference']);
                    @endphp
                    <article class="plan">
                        <div>
                            <h3><bdi>{{ $product->name }}</bdi></h3>
                            <p class="muted" style="margin:.2rem 0 0;font-size:.85rem">{{ $quote['new'] > $quote['old'] ? __('Upgrade') : ($quote['new'] < $quote['old'] ? __('Downgrade') : __('Same price')) }}</p>
                        </div>
                        <div class="price">{{ money($quote['new'], $service->currency) }}<small>{{ $service->billing_cycle->suffix() }}</small></div>
                        <p style="margin:0">
                            @if ($mode === \App\Models\PlanChange::MODE_INVOICE)
                                {!! __('You pay :amount now.', ['amount' => '<strong>'.e(money($quote['difference'], $service->currency)).'</strong>']) !!}
                            @elseif ($mode === \App\Models\PlanChange::MODE_RENEWAL)
                                {{ __('Changes on :date. Nothing to pay now.', ['date' => $service->next_due_date->translatedFormat('d M Y')]) }}
                            @elseif ($quote['difference'] < 0)
                                {!! __('Changes now, and :amount goes back to your wallet.', ['amount' => '<strong>'.e(money(-$quote['difference'], $service->currency)).'</strong>']) !!}
                            @else
                                {{ __('Changes now. Nothing to pay.') }}
                            @endif
                        </p>
                        <form method="POST" action="{{ route('client.services.change-plan.store', $service) }}" data-confirm="{{ __('Move to :plan?', ['plan' => $product->name]) }}">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                            <button class="btn btn-primary btn-block" type="submit">{{ __('Switch to :plan', ['plan' => $product->name]) }}</button>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    @endif
@endsection
