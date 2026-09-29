{{-- A settings page: the Settings menu on the left, and a header with the page's name and what it is for. --}}
@php
    $admin = auth('admin')->user();
    $menu = \App\Support\SettingsMenu::groups($admin);
    $current = \App\Support\SettingsMenu::current($admin, request());
@endphp
<div class="settings-layout">
    @include('admin.settings.nav', ['menu' => $menu, 'current' => $current])

    <div class="settings-body">
        <div class="page-head settings-head">
            <div>
                <a class="settings-back" href="{{ route('admin.settings.index') }}"><x-icon name="chevron-left" />{{ __('All settings') }}</a>
                <p class="settings-crumb">{{ __('Settings') }}@if ($current) <span aria-hidden="true">/</span> {{ $current['group'] }}@endif</p>
                <h1>{{ $current['label'] ?? __('Settings') }}</h1>
                @if ($current)<p>{{ $current['description'] }}</p>@endif
            </div>
            @isset($actions)<div class="form-actions">{{ $actions }}</div>@endisset
        </div>

        {{ $slot }}
    </div>
</div>
