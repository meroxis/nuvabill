@php
    $client = auth('web')->user();
    $cartCount = app(\App\Billing\Cart::class)->count();
    $sellsDomains = \App\Models\TldPrice::query()->where('is_enabled', true)->exists();
    $marketplaceStore = (bool) config('nuvabill.marketplace.store');
    $initials = $client ? mb_strtoupper(mb_substr((string) $client->first_name, 0, 1).mb_substr((string) $client->last_name, 0, 1)) : '';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>@yield('title') · {{ setting('company.name') }}</title>
    <meta name="description" content="@yield('description', setting('company.name'))">
    @include('partials.head', ['assets' => ['themes/nova/assets/theme.css', 'resources/js/app.js']])
    @include('theme::partials.brand-style')
    <x-extension-head area="client" />
</head>
<body>
<x-demo-banner />

<div x-data="{ open: false, account: false }" @keydown.escape.window="open = false; account = false">
    <div class="topbar">
        <div class="container">
            <span class="topbar-help">{{ __('Need help?') }} <a href="{{ $client ? route('client.tickets.create') : route('client.login') }}">{{ __('Open a support ticket') }}</a></span>
            <div class="topbar-links">
                <button class="topbar-link" type="button" onclick="nuvabillToggleTheme()" aria-label="{{ __('Switch light or dark mode') }}"><x-icon name="moon" /></button>
                <a class="topbar-link" href="{{ route('cart.show') }}" @if (request()->routeIs('cart.*', 'checkout.*')) aria-current="page" @endif><x-icon name="cart" />{{ __('Cart') }} ({{ $cartCount }})</a>
            </div>
        </div>
    </div>

    <header class="site-header">
        <div class="container">
            <a class="brand" href="{{ $client ? route('client.dashboard') : route('store.index') }}"><span class="brand-mark"><x-icon name="server" /></span><span class="brand-name">{{ setting('company.name') }}</span></a>

            <div class="header-actions">
                @if ($client)
                    <div class="account-menu" @click.outside="account = false">
                        <button class="account-button" type="button" @click="account = ! account" :aria-expanded="account" aria-haspopup="true">
                            <span class="account-avatar" aria-hidden="true">{{ $initials }}</span>
                            <span class="account-name">{{ $client->name }}</span>
                            <x-icon name="chevron-down" />
                        </button>
                        <div class="account-dropdown" x-show="account" x-cloak>
                            <a href="{{ route('client.account.edit') }}"><x-icon name="user" />{{ __('Your details') }}</a>
                            <a href="{{ route('client.account.edit') }}#two-factor"><x-icon name="shield" />{{ __('Security') }}</a>
                            <a href="{{ route('client.invoices.index') }}"><x-icon name="receipt" />{{ __('Invoices') }}</a>
                            <form method="POST" action="{{ route('client.logout') }}">
                                @csrf
                                <button type="submit"><x-icon name="logout" />{{ __('Sign out') }}</button>
                            </form>
                        </div>
                    </div>
                @else
                    <a class="btn btn-sm guest-only" href="{{ route('client.login') }}">{{ __('Sign in') }}</a>
                    <a class="btn btn-sm btn-primary guest-only" href="{{ route('client.register') }}">{{ __('Create account') }}</a>
                @endif
                <button class="icon-button menu-toggle" type="button" @click="open = ! open" :aria-expanded="open" aria-controls="main-nav" aria-label="{{ __('Menu') }}"><x-icon name="menu" /></button>
            </div>
        </div>
    </header>

    <nav class="main-nav" id="main-nav" :class="{ 'is-open': open }" aria-label="{{ __('Main') }}">
        <div class="container">
            <div class="main-nav-links">
                @if ($client)
                    <a href="{{ route('client.dashboard') }}" @if (request()->routeIs('client.dashboard')) aria-current="page" @endif><x-icon name="home" />{{ __('Dashboard') }}</a>
                    <a href="{{ route('client.services.index') }}" @if (request()->routeIs('client.services.*')) aria-current="page" @endif>{{ __('Services') }}</a>
                    @unless ($marketplaceStore)
                        <a href="{{ route('client.domains.index') }}" @if (request()->routeIs('client.domains.*')) aria-current="page" @endif>{{ __('Domains') }}</a>
                    @endunless
                    <a href="{{ route('client.invoices.index') }}" @if (request()->routeIs('client.invoices.*')) aria-current="page" @endif>{{ __('Billing') }}</a>
                    <a href="{{ route('client.tickets.index') }}" @if (request()->routeIs('client.tickets.*')) aria-current="page" @endif>{{ __('Support') }}</a>
                    @if ($marketplaceStore)
                        <a href="{{ route('marketplace.index') }}" @if (request()->routeIs('marketplace.*', 'developer.*')) aria-current="page" @endif>{{ __('Marketplace') }}</a>
                    @else
                        <a href="{{ route('store.index') }}" @if (request()->routeIs('store.*')) aria-current="page" @endif>{{ __('Store') }}</a>
                    @endif
                    <a class="nav-only-mobile" href="{{ route('client.account.edit') }}" @if (request()->routeIs('client.account.*')) aria-current="page" @endif>{{ __('Account') }}</a>
                @elseif ($marketplaceStore)
                    <a href="{{ route('marketplace.index') }}" @if (request()->routeIs('marketplace.index', 'marketplace.show')) aria-current="page" @endif><x-icon name="home" />{{ __('Marketplace') }}</a>
                    <a href="{{ route('marketplace.developers') }}" @if (request()->routeIs('marketplace.developers')) aria-current="page" @endif>{{ __('Developers') }}</a>
                    <a href="{{ route('client.login') }}" @if (request()->routeIs('client.login', 'client.register', 'client.password.*')) aria-current="page" @endif>{{ __('Client area') }}</a>
                @else
                    <a href="{{ route('store.index') }}" @if (request()->routeIs('store.index', 'store.group', 'store.product')) aria-current="page" @endif><x-icon name="home" />{{ __('Store') }}</a>
                    @if ($sellsDomains)
                        <a href="{{ route('store.domains') }}" @if (request()->routeIs('store.domains')) aria-current="page" @endif>{{ __('Domains') }}</a>
                    @endif
                    <a href="{{ route('client.login') }}" @if (request()->routeIs('client.login', 'client.register', 'client.password.*')) aria-current="page" @endif>{{ __('Client area') }}</a>
                @endif
            </div>
            @if ($client && $marketplaceStore)
                <a class="nav-cta" href="{{ route('marketplace.index') }}"><x-icon name="search" />{{ __('Browse the marketplace') }}</a>
            @elseif ($client)
                <a class="nav-cta" href="{{ route('store.index') }}"><x-icon name="plus" />{{ __('Order new services') }}</a>
            @elseif ($sellsDomains && ! $marketplaceStore)
                <a class="nav-cta" href="{{ route('store.domains') }}"><x-icon name="search" />{{ __('Find a domain') }}</a>
            @endif
        </div>
    </nav>
</div>

<main class="container page" id="main">
    @if ($client && request()->routeIs('client.*') && ! request()->routeIs('client.dashboard'))
        <nav class="breadcrumb" aria-label="{{ __('Breadcrumb') }}">
            <a href="{{ route('client.dashboard') }}">{{ __('Client area') }}</a>
            <span aria-hidden="true">/</span>
            <span>@yield('title')</span>
        </nav>
    @endif
    <x-flash />
    @yield('content')
</main>

<footer class="site-footer">
    <div class="container">
        <span class="footer-links">
            <span>&copy; {{ date('Y') }} {{ setting('company.name') }}</span>
            @if (filled(setting('orders.accept_terms_url')))<a href="{{ setting('orders.accept_terms_url') }}" target="_blank" rel="noopener">{{ __('Terms of service') }}</a>@endif
            @if (filled(setting('company.privacy_url')))<a href="{{ setting('company.privacy_url') }}" target="_blank" rel="noopener">{{ __('Privacy policy') }}</a>@endif
        </span>
        @if (\App\Support\Branding::showPoweredBy())
            <a class="powered" href="{{ \App\Support\Branding::PRODUCT_URL }}" target="_blank" rel="noopener">{{ __('Powered by') }} <b>{{ \App\Support\Branding::PRODUCT_NAME }}</b></a>
        @endif
    </div>
</footer>
</body>
</html>
