@extends('theme::layouts.app')

@section('title', __('White-label license'))
@section('description', __('Remove the "Powered by Nuvabill" credit from your client area, invoices and emails.'))

@section('content')
    <section class="mkt-hero" style="min-height:0">
        <div class="mkt-hero-text">
            <span class="mkt-tag"><span class="mkt-dot"></span>{{ __('For your brand') }}</span>
            <h1>{{ __('Your brand only. No "Powered by Nuvabill".') }}</h1>
            <p>{{ __('Nuvabill is free and stays free. A White-label license removes the small credit from your client area, invoices and emails, so your clients only see your company.') }}</p>
        </div>
    </section>

    <div class="two-col" style="margin-top:18px">
        <section class="card" style="display:grid;gap:.9rem">
            <h2>{{ __('What you get') }}</h2>
            <ul class="list-plain" style="display:grid;gap:.6rem;margin:0">
                <li><x-icon name="check" style="width:16px;height:16px;color:var(--nb-accent)" /> {{ __('No "Powered by Nuvabill" in the client area, on invoice PDFs or in emails') }}</li>
                <li><x-icon name="check" style="width:16px;height:16px;color:var(--nb-accent)" /> {{ __('Every feature and every update stays the same') }}</li>
                <li><x-icon name="check" style="width:16px;height:16px;color:var(--nb-accent)" /> {{ __('One license for one billing site. Test sites like localhost are free') }}</li>
                <li><x-icon name="check" style="width:16px;height:16px;color:var(--nb-accent)" /> {{ __('Priority help from the Nuvabill team') }}</li>
                <li><x-icon name="check" style="width:16px;height:16px;color:var(--nb-accent)" /> {{ __('Move the key to a new address from your account when you need to') }}</li>
            </ul>
            <p class="muted" style="margin:0">{{ __('After you pay, the key arrives by email. Enter it in your Nuvabill under Settings → License. If you stop paying, the credit comes back; nothing else changes.') }}</p>
        </section>

        <aside class="card" style="display:grid;gap:1rem;align-content:start">
            @if ($price)
                {{-- The terms come from the price row that is sold, so the page never promises a different deal. --}}
                <div style="display:flex;align-items:baseline;gap:10px"><span class="mkt-big-price">{{ money($price->price + $price->setup_fee, $price->currency) }}</span>@if ($price->billing_cycle->isRecurring())<span class="muted">{{ __('the first year') }}</span>@endif</div>
                @if ($price->billing_cycle->isRecurring())
                    <p class="muted" style="margin:0">{{ __('Then :price a year. Cancel any time.', ['price' => money($price->price, $price->currency)]) }}</p>
                @endif
                <form method="POST" action="{{ route('cart.store') }}" style="display:grid;gap:.8rem">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $item->product_id }}">
                    <input type="hidden" name="billing_cycle" value="{{ $price->billing_cycle->value }}">
                    <x-input name="domain" :label="__('Address of your Nuvabill')" placeholder="billing.yourhost.com" required autocomplete="off" />
                    <button class="btn btn-primary btn-block" type="submit"><x-icon name="cart" />{{ __('Buy the White-label license') }}</button>
                </form>
            @endif
        </aside>
    </div>
@endsection
