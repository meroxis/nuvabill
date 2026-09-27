@extends('theme::layouts.app')

@section('title', __('Sell on the Nuvabill Marketplace'))
@section('description', __('Build themes and extensions for Nuvabill and keep :share% of every sale.', ['share' => $share]))

@section('content')
    <section class="mkt-hero" style="min-height:0">
        <div class="mkt-hero-text">
            <span class="mkt-tag"><span class="mkt-dot"></span>{{ __('For developers') }}</span>
            <h1>{{ __('Sell your themes and extensions') }}</h1>
            <p>{{ __('Hosting companies run Nuvabill to bill their clients. Build something they need and we sell it for you. You keep :share% of every sale, and every yearly update renewal.', ['share' => $share]) }}</p>
            <div class="mkt-hero-actions">
                @if ($developer)
                    <a class="btn btn-primary" href="{{ route('developer.dashboard') }}">{{ __('Open your developer account') }}</a>
                @else
                    <a class="btn btn-primary" href="{{ route('developer.join') }}">{{ __('Become a developer') }}</a>
                @endif
                <a class="btn mkt-glass" href="https://nuvabill.com/docs/developers/" target="_blank" rel="noopener">{{ __('Developer docs') }}</a>
            </div>
        </div>
    </section>

    <div class="mkt-steps">
        <article class="card"><span class="mkt-num">1</span><h2>{{ __('Build it') }}</h2><p>{{ __('Themes are Blade views and CSS. Extensions are PHP classes with an extension.json. The docs have starter kits.') }}</p></article>
        <article class="card"><span class="mkt-num">2</span><h2>{{ __('Send it for review') }}</h2><p>{{ __('Upload a zip. Automatic checks run at once, then a person installs it and tests every screen. We tell you what to fix.') }}</p></article>
        <article class="card"><span class="mkt-num">3</span><h2>{{ __('We sign and sell it') }}</h2><p>{{ __('Approved versions are signed, listed and installed in one click. We take the payments and send license keys.') }}</p></article>
        <article class="card"><span class="mkt-num">4</span><h2>{{ __('Get paid monthly') }}</h2><p>{{ __('You keep :share% of each sale and renewal. The :fee% fee pays for payments, license keys, downloads and reviews.', ['share' => $share, 'fee' => 100 - $share]) }}</p></article>
    </div>

    <section class="card" style="display:grid;gap:.8rem">
        <h2 style="font-size:1.1rem">{{ __('Review rules, in short') }}</h2>
        <ul class="features">
            <li>{{ __('Readable code only. No encoded, hidden or obfuscated code.') }}</li>
            <li>{{ __('List every outside address your code connects to in its permissions.') }}</li>
            <li>{{ __('No tracking, ads or selling of site or client data.') }}</li>
            <li>{{ __('Keep the "Powered by Nuvabill" credit where Nuvabill shows it.') }}</li>
            <li>{{ __('You own your code, or have the right to sell it.') }}</li>
        </ul>
    </section>
@endsection
