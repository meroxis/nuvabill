@php
    $client = auth('web')->user();
    $cartCount = app(\App\Billing\Cart::class)->count();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>@yield('title') · {{ setting('company.name') }}</title>
    <meta name="description" content="@yield('description', setting('company.name'))">
    @include('partials.head', ['assets' => ['themes/nova/assets/theme.css', 'resources/js/app.js']])
    @include('theme::partials.brand-style')
</head>
<body>
<x-demo-banner />
<header class="site-header" x-data="{ open: false }" @keydown.escape.window="open = false">
    <div class="container" style="position:relative">
        <a class="brand" href="{{ route('store.index') }}"><span class="brand-mark"></span><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ setting('company.name') }}</span></a>
        <button class="icon-button menu-toggle" type="button" @click="open = ! open" :aria-expanded="open" aria-label="{{ __('Menu') }}"><x-icon name="menu" /></button>
        <nav class="site-nav" :class="{ 'is-open': open }" aria-label="{{ __('Main') }}">
            <a href="{{ route('store.index') }}" @if (request()->routeIs('store.*')) aria-current="page" @endif>{{ __('Store') }}</a>
            @if ($client)
                <a href="{{ route('client.dashboard') }}" @if (request()->routeIs('client.*')) aria-current="page" @endif>{{ __('My account') }}</a>
            @else
                <a href="{{ route('client.login') }}" @if (request()->routeIs('client.login')) aria-current="page" @endif>{{ __('Sign in') }}</a>
            @endif
            <a class="cart-link" href="{{ route('cart.show') }}" @if (request()->routeIs('cart.*', 'checkout.*')) aria-current="page" @endif>
                {{ __('Cart') }}@if ($cartCount > 0)<span class="cart-count">{{ $cartCount }}</span>@endif
            </a>
            @if ($client)
                <form method="POST" action="{{ route('client.logout') }}" style="display:contents">
                    @csrf
                    <a href="#" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Sign out') }}</a>
                </form>
            @endif
            <button class="icon-button" type="button" onclick="nuvabillToggleTheme()" aria-label="{{ __('Switch light or dark mode') }}" style="width:36px;height:36px"><x-icon name="moon" /></button>
        </nav>
    </div>
</header>

@if ($client && request()->routeIs('client.*'))
    <nav class="client-tabs" aria-label="{{ __('Client area') }}">
        <div class="container">
            <a href="{{ route('client.dashboard') }}" @if (request()->routeIs('client.dashboard')) aria-current="page" @endif>{{ __('Overview') }}</a>
            <a href="{{ route('client.services.index') }}" @if (request()->routeIs('client.services.*')) aria-current="page" @endif>{{ __('Services') }}</a>
            <a href="{{ route('client.invoices.index') }}" @if (request()->routeIs('client.invoices.*')) aria-current="page" @endif>{{ __('Invoices') }}</a>
            <a href="{{ route('client.tickets.index') }}" @if (request()->routeIs('client.tickets.*')) aria-current="page" @endif>{{ __('Support') }}</a>
            <a href="{{ route('client.account.edit') }}" @if (request()->routeIs('client.account.*')) aria-current="page" @endif>{{ __('Account') }}</a>
        </div>
    </nav>
@endif

<main class="container page" id="main">
    <x-flash />
    @yield('content')
</main>

<footer class="site-footer">
    <div class="container">
        <span>&copy; {{ date('Y') }} {{ setting('company.name') }}</span>
        @if (\App\Support\Branding::showPoweredBy())
            <a class="powered" href="{{ \App\Support\Branding::PRODUCT_URL }}" target="_blank" rel="noopener">{{ __('Powered by') }} <b>{{ \App\Support\Branding::PRODUCT_NAME }}</b></a>
        @endif
    </div>
</footer>
</body>
</html>
