@extends('theme::layouts.app')

@section('title', __('Checkout'))

@section('content')
    <div class="page-title"><div><h1>{{ __('Checkout') }}</h1></div></div>

    <div class="two-col">
        <div style="display:grid;gap:16px">
            @if ($client)
                <section class="card" style="display:grid;gap:.6rem">
                    <h2 style="font-size:1.05rem">{{ __('Billing to') }}</h2>
                    <p style="margin:0"><b>{{ $client->name }}</b>@if ($client->company_name) · {{ $client->company_name }}@endif<br><span class="muted">{{ $client->email }}</span></p>
                </section>
                <form method="POST" action="{{ route('checkout.store') }}" class="card" style="display:grid;gap:1rem">
                    @csrf
                    @if ($termsUrl)
                        <x-checkbox name="accept_terms" :label="__('I agree to the terms of service')" />
                        <p class="muted" style="margin:0;font-size:.85rem">
                            <a href="{{ $termsUrl }}" target="_blank" rel="noopener">{{ __('Read the terms of service') }}</a>
                            @if (filled(setting('company.privacy_url'))) · <a href="{{ setting('company.privacy_url') }}" target="_blank" rel="noopener">{{ __('Privacy policy') }}</a>@endif
                        </p>
                    @endif
                    <x-captcha form="checkout" />
                    <button class="btn btn-primary" type="submit">{{ __('Place order') }}</button>
                    <p class="muted" style="margin:0;font-size:.85rem">{{ __('Next you choose how to pay. Your service is set up as soon as the payment arrives.') }}</p>
                </form>
            @else
                <section class="card" style="display:grid;gap:1rem">
                    <h2 style="font-size:1.05rem">{{ __('New here?') }}</h2>
                    <p class="muted" style="margin:0">{{ __('Create an account to finish your order. It takes a minute.') }}</p>
                    <a class="btn btn-primary" href="{{ route('client.register') }}">{{ __('Create an account') }}</a>
                </section>
                <section class="card" style="display:grid;gap:1rem">
                    <h2 style="font-size:1.05rem">{{ __('Already a customer?') }}</h2>
                    <form method="POST" action="{{ route('client.login') }}" style="display:grid;gap:.9rem">
                        @csrf
                        <x-input name="email" type="email" :label="__('Email')" required autocomplete="username" />
                        <x-input name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
                        <x-captcha form="client_login" />
                        <button class="btn" type="submit">{{ __('Sign in and continue') }}</button>
                    </form>
                </section>
            @endif
        </div>

        <aside class="card summary">
            <h2 style="font-size:1.05rem">{{ __('Order summary') }}</h2>
            @foreach ($lines as $line)
                <div class="summary-row">
                    <span>{{ $line->title() }}<br><span class="muted" style="font-size:.82rem">{{ $line->summary() }}</span>@foreach ($line->addons as $addon)<br><span class="muted" style="font-size:.82rem">+ {{ $addon['name'] }}</span>@endforeach</span>
                    <span class="num">{{ money($line->dueToday(), $currency) }}</span>
                </div>
            @endforeach
            @if ($discount)
                <div class="summary-row" style="color:var(--nb-good)"><span>{{ __('Coupon :code', ['code' => $coupon?->code]) }}</span><span class="num">-{{ money($discount, $currency) }}</span></div>
            @elseif ($couponProblem)
                <p class="error" style="margin:0">{{ $couponProblem }}</p>
            @endif
            <div class="summary-row total"><span>{{ __('Total due today') }}</span><span class="num">{{ money($total, $currency) }}</span></div>
        </aside>
    </div>
@endsection
