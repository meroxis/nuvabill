<x-layouts.admin :title="__('Settings')">
    <div class="settings-home" x-data="{ q: '' }">
        <div class="page-head">
            <div>
                <h1>{{ __('Settings') }}</h1>
                <p>{{ __('Everything about how your business runs in Nuvabill.') }}</p>
            </div>
            <label class="settings-find">
                <x-icon name="search" />
                <input type="search" x-model="q" placeholder="{{ __('Find a setting') }}" aria-label="{{ __('Find a setting') }}" autocomplete="off"
                    @keydown.enter.prevent="const link = [...$root.querySelectorAll('a[data-find]')].find((a) => a.offsetParent); if (link) window.location = link.href">
            </label>
        </div>

        <div class="settings-cards">
            @foreach ($cards as $card)
                <section class="settings-card" x-show="! q || [...$el.querySelectorAll('a[data-find]')].some((a) => a.dataset.find.includes(q.toLowerCase()))">
                    @foreach ($card as $group)
                        <div class="settings-card-group" x-show="! q || [...$el.querySelectorAll('a[data-find]')].some((a) => a.dataset.find.includes(q.toLowerCase()))">
                            <h2>{{ $group['label'] }}</h2>
                            @foreach ($group['items'] as $item)
                                <a class="settings-link" href="{{ $item['url'] }}" x-show="! q || $el.dataset.find.includes(q.toLowerCase())"
                                    data-find="{{ mb_strtolower($item['label'].' '.$item['summary'].' '.$item['description'].' '.$item['keywords']) }}">
                                    <span class="settings-link-icon"><x-icon :name="$item['icon']" /></span>
                                    <span class="settings-link-text">
                                        <b>{{ $item['label'] }}</b>
                                        <span>{{ $item['summary'] }}</span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>

        <p class="settings-none" x-show="q && ! [...$root.querySelectorAll('a[data-find]')].some((a) => a.dataset.find.includes(q.toLowerCase()))" x-cloak>{{ __('No setting matches.') }}</p>
    </div>
</x-layouts.admin>
