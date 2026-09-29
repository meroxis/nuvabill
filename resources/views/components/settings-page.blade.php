{{-- A settings page: a way back to the Settings home, and a header with the page's name and what it is for. --}}
@php
    $current = \App\Support\SettingsMenu::current(auth('admin')->user(), request());
@endphp
<div class="settings-body">
    <div class="page-head settings-head">
        <div>
            <a class="settings-back" href="{{ route('admin.settings.index') }}"><x-icon name="chevron-left" />{{ __('All settings') }}</a>
            <p class="settings-crumb"><a href="{{ route('admin.settings.index') }}">{{ __('Settings') }}</a>@if ($current) <span aria-hidden="true">/</span> {{ $current['group'] }}@endif</p>
            <h1>{{ $current['label'] ?? __('Settings') }}</h1>
            @if ($current)<p>{{ $current['description'] }}</p>@endif
        </div>
        @isset($actions)<div class="form-actions">{{ $actions }}</div>@endisset
    </div>

    {{ $slot }}
</div>
