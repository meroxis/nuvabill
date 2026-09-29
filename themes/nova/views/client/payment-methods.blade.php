@extends('theme::layouts.app')

@section('title', __('Payment methods'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Billing') }}</p>
            <h1 style="margin-top:.3rem">{{ __('Payment methods') }}</h1>
            <p>{{ __('Save a card or PayPal to pay your renewals automatically.') }}</p>
        </div>
        <a class="btn" href="{{ route('client.invoices.index') }}"><x-icon name="receipt" />{{ __('Invoices') }}</a>
    </div>

    <div class="two-col">
        <div style="display:grid;gap:14px;align-content:start">
            @if ($autoPayOn)
                <form method="POST" action="{{ route('client.account.payment-methods.automatic') }}" class="card" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="auto_pay" value="{{ $client->auto_pay ? 0 : 1 }}">
                    <div style="flex:1;min-width:220px">
                        <h2 style="font-size:1.05rem">{{ __('Pay renewals automatically') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">
                            @if ($client->auto_pay)
                                {{ __('On. We charge your default payment method when a renewal is due, and email you before.') }}
                            @else
                                {{ __('Off. You pay each invoice yourself.') }}
                            @endif
                        </p>
                    </div>
                    <button class="btn {{ $client->auto_pay ? '' : 'btn-primary' }}" type="submit">{{ $client->auto_pay ? __('Turn off') : __('Turn on') }}</button>
                </form>
            @endif

            <section class="card" style="display:grid;gap:.8rem">
                <h2 style="font-size:1.05rem">{{ __('Saved') }}</h2>
                @forelse ($methods as $method)
                    <div class="summary-row" style="align-items:center;flex-wrap:wrap;gap:.6rem">
                        <span style="display:inline-flex;gap:.6rem;align-items:center;min-width:0;flex:1">
                            <x-icon :name="$method->type === 'paypal' ? 'globe' : 'card'" style="width:20px;height:20px;flex:none" />
                            <span style="min-width:0;overflow-wrap:anywhere">
                                <b>{{ $method->label() }}</b>
                                @if ($method->expiry())
                                    <span class="muted" style="display:block;font-size:.82rem">{{ $method->isExpired() ? __('Expired :date', ['date' => $method->expiry()]) : __('Expires :date', ['date' => $method->expiry()]) }}</span>
                                @endif
                            </span>
                        </span>
                        @if ($method->is_default)
                            <span class="pill" data-tone="good">{{ __('Default') }}</span>
                        @else
                            <form method="POST" action="{{ route('client.account.payment-methods.default', $method) }}">
                                @csrf
                                @method('PUT')
                                <button class="btn btn-sm" type="submit">{{ __('Make default') }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('client.account.payment-methods.destroy', $method) }}" data-confirm="{{ __('Remove :method? It will not pay your renewals anymore.', ['method' => $method->label()]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm" type="submit">{{ __('Remove') }}</button>
                        </form>
                    </div>
                @empty
                    <div class="empty"><strong>{{ __('Nothing saved yet') }}</strong>{{ __('Add a card or PayPal, or tick “Save it” when you pay an invoice.') }}</div>
                @endforelse

                @if ($gateways->isNotEmpty())
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                        @foreach ($gateways as $slug => $gateway)
                            <form method="POST" action="{{ route('client.account.payment-methods.store', $slug) }}">
                                @csrf
                                <button class="btn {{ $loop->first ? 'btn-primary' : '' }}" type="submit"><x-icon name="plus" />{{ $slug === 'paypal' ? __('Add PayPal') : __('Add a card') }}</button>
                            </form>
                        @endforeach
                    </div>
                @else
                    <p class="muted" style="margin:0;font-size:.88rem">{{ __('Saving a payment method is not available right now.') }}</p>
                @endif
                <p class="muted" style="margin:0;font-size:.82rem">{{ __('Cards are kept by the payment company, and PayPal accounts by PayPal. We never see or keep your full card number.') }}</p>
            </section>
        </div>

        <aside style="display:grid;gap:14px;align-content:start">
            @if ($autoPayOn && $client->auto_pay && $methods->isNotEmpty())
                <section class="card" style="display:grid;gap:.4rem">
                    <h2 style="font-size:1.05rem">{{ __('Next automatic payments') }}</h2>
                    @foreach ($invoices as $row)
                        <div class="summary-row">
                            <span><a href="{{ route('client.invoices.show', $row['invoice']) }}">{{ $row['invoice']->displayNumber() }}</a><span class="muted" style="display:block;font-size:.82rem">{{ $row['date']->translatedFormat('d M Y') }}</span></span>
                            <b class="num">{{ money($row['invoice']->balance(), $row['invoice']->currency) }}</b>
                        </div>
                    @endforeach
                    @foreach ($services as $service)
                        <div class="summary-row">
                            <span>{{ $service->label() }}<span class="muted" style="display:block;font-size:.82rem">{{ __('renews :date', ['date' => $service->next_due_date->translatedFormat('d M Y')]) }}</span></span>
                            <b class="num">{{ money($service->recurring_amount, $service->currency) }}</b>
                        </div>
                    @endforeach
                    @if ($invoices->isEmpty() && $services->isEmpty())
                        <p class="muted" style="margin:0;font-size:.88rem">{{ __('No renewals are coming up.') }}</p>
                    @endif
                    <p class="muted" style="margin:.4rem 0 0;font-size:.82rem">{{ __('Wallet credit is used first. You can still pay any invoice yourself before its date.') }}</p>
                </section>
            @endif
        </aside>
    </div>
@endsection
