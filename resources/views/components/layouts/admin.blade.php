@props(['title'])
@php
    $admin = auth('admin')->user();
    $can = fn (string $permission): bool => $admin->hasPermission($permission);
    $nav = array_filter([
        ['route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'icon' => 'home', 'label' => __('Dashboard'), 'show' => true],
        ['route' => 'admin.clients.index', 'match' => 'admin.clients.*', 'icon' => 'users', 'label' => __('Clients'), 'show' => $can('clients.view')],
        ['route' => 'admin.orders.index', 'match' => 'admin.orders.*', 'icon' => 'cart', 'label' => __('Orders'), 'show' => $can('orders.manage'), 'count' => $pendingOrders],
        ['route' => 'admin.services.index', 'match' => 'admin.services.*', 'icon' => 'box', 'label' => __('Services'), 'show' => $can('services.manage')],
        ['route' => 'admin.domains.index', 'match' => 'admin.domains.*', 'icon' => 'globe', 'label' => __('Domains'), 'show' => $can('domains.manage')],
        ['route' => 'admin.invoices.index', 'match' => 'admin.invoices.*', 'icon' => 'receipt', 'label' => __('Invoices'), 'show' => $can('billing.view')],
        ['route' => 'admin.tickets.index', 'match' => 'admin.tickets.*', 'icon' => 'ticket', 'label' => __('Support'), 'show' => $can('support.manage'), 'count' => $ticketsAwaitingReply],
    ], fn (array $item): bool => $item['show']);
    $setup = array_filter([
        ['route' => 'admin.products.index', 'match' => ['admin.products.*', 'admin.product-groups.*', 'admin.product-addons.*'], 'icon' => 'store', 'label' => __('Products'), 'show' => $can('products.manage')],
        ['route' => 'admin.coupons.index', 'match' => 'admin.coupons.*', 'icon' => 'tag', 'label' => __('Coupons'), 'show' => $can('coupons.manage')],
        ['route' => 'admin.servers.index', 'match' => 'admin.servers.*', 'icon' => 'server', 'label' => __('Servers'), 'show' => $can('products.manage')],
        ['route' => 'admin.marketplace.index', 'match' => 'admin.marketplace.*', 'icon' => 'puzzle', 'label' => __('Marketplace'), 'show' => $can('marketplace.manage'), 'count' => $marketplaceUpdates ?: null],
        ['route' => 'admin.settings.edit', 'match' => ['admin.settings.*'], 'icon' => 'settings', 'label' => __('Settings'), 'show' => $can('settings.manage') || $can('staff.manage')],
        ['route' => 'admin.updates.index', 'match' => 'admin.updates.*', 'icon' => 'refresh', 'label' => __('Updates'), 'show' => $can('system.update'), 'count' => $updateAvailable ? 1 : null],
    ], fn (array $item): bool => $item['show']);
    $settingsRoute = $can('settings.manage') ? 'admin.settings.edit' : 'admin.settings.staff.index';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>{{ $title }} · {{ setting('company.name') }}</title>
    @include('partials.head', ['assets' => ['resources/css/admin.css', 'resources/js/app.js']])
    <x-extension-head area="admin" />
</head>
<body>
<div class="admin-shell" x-data="{ menu: false }" @keydown.escape.window="menu = false">
    <div class="side-overlay" x-show="menu" x-cloak @click="menu = false"></div>

    <aside class="admin-side" :class="{ 'is-open': menu }" aria-label="{{ __('Admin navigation') }}">
        <a class="admin-brand" href="{{ route('admin.dashboard') }}"><x-logo /><span>{{ \App\Support\Branding::PRODUCT_NAME }}</span></a>

        @foreach ($nav as $item)
            <a class="nav-link" href="{{ route($item['route']) }}" @if (request()->routeIs($item['match'])) aria-current="page" @endif>
                <x-icon :name="$item['icon']" />{{ $item['label'] }}
                @if (! empty($item['count']))<span class="count">{{ $item['count'] }}</span>@endif
            </a>
        @endforeach

        @if ($setup)
            <div class="nav-label">{{ __('Setup') }}</div>
            @foreach ($setup as $item)
                <a class="nav-link" href="{{ route($item['route'] === 'admin.settings.edit' ? $settingsRoute : $item['route']) }}" @if (request()->routeIs($item['match'])) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" />{{ $item['label'] }}
                    @if (! empty($item['count']))<span class="count">{{ $item['count'] }}</span>@endif
                </a>
            @endforeach
        @endif

        <div class="side-foot">
            <a href="{{ route('admin.profile.edit') }}" class="avatar" title="{{ __('Your profile') }}">{{ $admin->initials() }}</a>
            <div style="min-width:0;flex:1">
                <a href="{{ route('admin.profile.edit') }}" class="row-link" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $admin->name }}</a>
                <span class="faint" style="font-size:.76rem">{{ $admin->role?->name }}</span>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="icon-btn" type="submit" title="{{ __('Sign out') }}" aria-label="{{ __('Sign out') }}"><x-icon name="logout" /></button>
            </form>
        </div>
    </aside>

    <div class="admin-main">
        <x-demo-banner />
        <header class="admin-topbar">
            <button class="icon-btn mobile-only" type="button" @click="menu = true" aria-label="{{ __('Open menu') }}"><x-icon name="menu" /></button>
            @if ($can('clients.view'))
                <form class="search-box" method="GET" action="{{ route('admin.clients.index') }}" role="search">
                    <x-icon name="search" />
                    <input class="input" type="search" name="q" value="{{ request()->routeIs('admin.clients.index') ? request('q') : '' }}" placeholder="{{ __('Search clients by name, email or ID') }}" aria-label="{{ __('Search clients') }}">
                </form>
            @endif
            <span style="flex:1"></span>
            @php
                $quickNew = array_filter([
                    $can('clients.manage') ? ['url' => route('admin.clients.create'), 'icon' => 'user', 'label' => __('New client')] : null,
                    $can('billing.manage') ? ['url' => route('admin.invoices.create'), 'icon' => 'receipt', 'label' => __('New invoice')] : null,
                    $can('products.manage') ? ['url' => route('admin.products.create'), 'icon' => 'box', 'label' => __('New product')] : null,
                ]);
            @endphp
            @if ($quickNew)
                <div class="quick-new" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                    <button class="btn btn-primary btn-sm" type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="true"><x-icon name="plus" />{{ __('New') }}<x-icon name="chevron-down" /></button>
                    <div class="dropdown" x-show="open" x-cloak>
                        @foreach ($quickNew as $item)
                            <a href="{{ $item['url'] }}"><x-icon :name="$item['icon']" />{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
            <a class="btn btn-sm" href="{{ route('store.index') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('View store') }}</a>
            <button class="icon-btn" type="button" onclick="nuvabillToggleTheme()" aria-label="{{ __('Switch light or dark mode') }}"><x-icon name="moon" /></button>
        </header>

        <main class="admin-content" id="main">
            @foreach ($licenseWarnings as $warning)
                <div class="flash" data-tone="warn" role="alert">
                    <span><b>{{ __(':name is unlicensed.', ['name' => $warning['name']]) }}</b> {{ $warning['message'] }}</span>
                    @if ($can('marketplace.manage'))<a class="btn btn-sm" href="{{ route('admin.marketplace.show', $warning['slug']) }}">{{ __('Fix it') }}</a>@endif
                </div>
            @endforeach
            <x-flash />
            {{ $slot }}
        </main>
    </div>

    <nav class="tabbar" aria-label="{{ __('Quick navigation') }}">
        <a href="{{ route('admin.dashboard') }}" @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif><x-icon name="home" />{{ __('Home') }}</a>
        @if ($can('clients.view'))
            <a href="{{ route('admin.clients.index') }}" @if (request()->routeIs('admin.clients.*')) aria-current="page" @endif><x-icon name="users" />{{ __('Clients') }}</a>
        @endif
        @if ($can('billing.view'))
            <a href="{{ route('admin.invoices.index') }}" @if (request()->routeIs('admin.invoices.*')) aria-current="page" @endif><x-icon name="receipt" />{{ __('Invoices') }}</a>
        @endif
        @if ($can('support.manage'))
            <a href="{{ route('admin.tickets.index') }}" @if (request()->routeIs('admin.tickets.*')) aria-current="page" @endif><x-icon name="ticket" />{{ __('Support') }}</a>
        @endif
        <a href="#" @click.prevent="menu = true"><x-icon name="menu" />{{ __('More') }}</a>
    </nav>
</div>
</body>
</html>
