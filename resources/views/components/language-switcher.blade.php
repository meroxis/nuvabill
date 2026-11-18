@props(['action', 'locales'])
@php
    $current = app()->getLocale();
    $all = \App\Support\Locales::ALL;
    $currentName = $locales[$current] ?? ($all[$current]['native'] ?? $current);
    // On pages search engines may show, the language versions they may also show are plain links
    // they can follow. A click still goes through the form, so signed-in clients keep their choice.
    $seo = app(\App\Seo\Seo::class);
    $linked = count($locales) > 1 && ! request()->routeIs('admin.*') && $seo->canonicalPath() === null && $seo->isIndexable(request())
        ? array_intersect(\App\Seo\LanguageVersions::offered($seo->readyLanguages()), array_keys($locales))
        : [];
@endphp
@if (count($locales) > 1)
    <div class="lang-menu" x-data="{ open: false, search: '', choose(code) { const choice = this.$refs.choice; choice.name = 'locale'; choice.value = code; choice.form.submit(); } }" @keydown.escape.window="open = false" @click.outside="open = false">
        <button type="button" {{ $attributes->class('lang-menu-button') }} @click="open = ! open; if (open) $nextTick(() => $refs.search && $refs.search.focus())"
                :aria-expanded="open.toString()" aria-haspopup="true" aria-label="{{ __('Language') }}: {{ $currentName }}">
            <x-icon name="globe" />
            <span class="lang-menu-current">{{ $currentName }}</span>
            <x-icon name="chevron-down" class="lang-menu-chevron" />
        </button>

        <form method="POST" action="{{ $action }}" class="lang-menu-panel" x-show="open" x-cloak x-transition.opacity.duration.150ms>
            @csrf
            @if ($linked !== [])
                <input type="hidden" x-ref="choice">
            @endif
            @if (count($locales) > 8)
                <div class="lang-menu-search">
                    <x-icon name="search" />
                    <input x-ref="search" x-model="search" type="search" autocomplete="off" placeholder="{{ __('Search languages') }}" aria-label="{{ __('Search languages') }}">
                </div>
            @endif
            <div class="lang-menu-list">
                @foreach ($locales as $code => $native)
                    @php
                        $name = \App\Support\Locales::displayName($code);
                        $isLink = in_array($code, $linked, true);
                    @endphp
                    @if ($isLink)
                        <a href="{{ \App\Seo\SiteAddress::url(request()->path(), $code, $seo->page()) }}" hreflang="{{ \App\Support\Locales::htmlLang($code) }}" lang="{{ \App\Support\Locales::htmlLang($code) }}" @click.prevent="choose(@js($code))"
                    @else
                        <button type="submit" name="locale" value="{{ $code }}"
                    @endif
                            @class(['lang-menu-item', 'is-current' => $code === $current])
                            data-search="{{ mb_strtolower($native.' '.$name.' '.($all[$code]['name'] ?? '').' '.$code) }}"
                            x-show="! search || $el.dataset.search.includes(search.toLowerCase())"
                            @if ($code === $current) aria-current="true" @endif>
                        <span class="lang-menu-code">{{ $all[$code]['short'] ?? strtoupper($code) }}</span>
                        <span class="lang-menu-names">
                            <span class="lang-menu-native" lang="{{ \App\Support\Locales::htmlLang($code) }}" dir="{{ \App\Support\Locales::isRtl($code) ? 'rtl' : 'ltr' }}">{{ $native }}</span>
                            @if (mb_strtolower($name) !== mb_strtolower($native))
                                <span class="lang-menu-local" @if ($isLink) lang="{{ \App\Support\Locales::htmlLang($current) }}" @endif>{{ $name }}</span>
                            @endif
                        </span>
                        @if ($code === $current)
                            <x-icon name="check" class="lang-menu-check" />
                        @endif
                    @if ($isLink)
                        </a>
                    @else
                        </button>
                    @endif
                @endforeach
                <p class="lang-menu-empty" x-show="search && ! [...$root.querySelectorAll('[data-search]')].some((item) => item.dataset.search.includes(search.toLowerCase()))" x-cloak>{{ __('No languages found.') }}</p>
            </div>
        </form>

        <noscript>
            <form method="POST" action="{{ $action }}">
                @csrf
                <select name="locale" aria-label="{{ __('Language') }}">
                    @foreach ($locales as $code => $native)
                        <option value="{{ $code }}" @selected($code === $current)>{{ $native }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm" type="submit">{{ __('Change') }}</button>
            </form>
        </noscript>
    </div>
@endif
