@php
    $menu ??= \App\Support\SettingsMenu::groups(auth('admin')->user());
    $currentKey = $current['key'] ?? null;
@endphp
<nav class="settings-menu" aria-label="{{ __('Settings sections') }}" x-data="{ q: '' }">
    <label class="settings-find">
        <x-icon name="search" />
        <input type="search" x-model="q" placeholder="{{ __('Find a setting') }}" aria-label="{{ __('Find a setting') }}" autocomplete="off"
            @keydown.enter.prevent="const link = [...$root.querySelectorAll('a[data-find]')].find((a) => a.offsetParent); if (link) window.location = link.href">
    </label>
    @foreach ($menu as $group)
        <div class="settings-group" x-show="! q || [...$el.querySelectorAll('a[data-find]')].some((a) => a.dataset.find.includes(q.toLowerCase()))">
            <div class="settings-group-label">{{ $group['label'] }}</div>
            @foreach ($group['items'] as $item)
                <a href="{{ $item['url'] }}" data-find="{{ mb_strtolower($item['label'].' '.$item['description'].' '.$item['keywords']) }}"
                    x-show="! q || $el.dataset.find.includes(q.toLowerCase())" @if ($item['key'] === $currentKey) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" />
                    <span class="settings-item">
                        <span>{{ $item['label'] }}</span>
                        <span class="settings-item-text">{{ $item['description'] }}</span>
                    </span>
                    @if ($item['external'])<x-icon name="external" class="settings-out" />@else<x-icon name="chevron-right" class="settings-go" />@endif
                </a>
            @endforeach
        </div>
    @endforeach
    <p class="settings-none" x-show="q && ! [...$root.querySelectorAll('a[data-find]')].some((a) => a.dataset.find.includes(q.toLowerCase()))" x-cloak>{{ __('No setting matches.') }}</p>
</nav>
