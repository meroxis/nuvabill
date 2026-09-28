<x-layouts.admin :title="__('Marketplace')">
    <div class="page-head">
        <div>
            <h1>{{ __('Marketplace') }}</h1>
            <p>{{ __('Themes, order forms and extensions for your Nuvabill. Every package is reviewed and signed before it installs.') }}</p>
        </div>
        <form method="GET" role="search" style="display:flex;gap:8px">
            @if ($tab !== 'discover')<input type="hidden" name="tab" value="{{ $tab }}">@endif
            <input class="input" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search the marketplace') }}" aria-label="{{ __('Search the marketplace') }}" style="width:260px">
        </form>
    </div>

    <nav class="market-tabs" aria-label="{{ __('Marketplace sections') }}">
        @foreach (['discover' => __('Discover'), 'themes' => __('Themes'), 'orderforms' => __('Order forms'), 'extensions' => __('Extensions'), 'installed' => __('Installed'), 'updates' => __('Updates')] as $key => $label)
            <a href="{{ route('admin.marketplace.index', $key === 'discover' ? [] : ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>
                {{ $label }}
                @if ($key === 'installed')<span class="count">{{ $installedCount }}</span>@endif
                @if ($key === 'updates' && $updatesCount)<span class="count" style="background:var(--nb-accent);border-color:var(--nb-accent);color:var(--nb-accent-ink)">{{ $updatesCount }}</span>@endif
            </a>
        @endforeach
    </nav>

    @if ($error && $tab !== 'installed')
        <div class="flash" data-tone="warn"><span>{{ $error }}</span></div>
    @endif

    @if ($featured)
        <section class="market-hero">
            <div class="market-hero-text">
                <span class="tag">{{ __('Featured :type', ['type' => \App\Support\Locales::inSentence($featured->type->label())]) }}</span>
                <h2 style="margin:0;font-size:2rem;font-weight:800;letter-spacing:-.02em;color:#fff">{{ $featured->name }}</h2>
                <p>{{ $featured->summary }}</p>
                <div style="margin-top:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <span style="font-size:1.8rem;font-weight:800">{{ $featured->priceLabel() }}</span>
                    @unless ($featured->isFree())<span style="font-size:.78rem;color:#9fb2c2;line-height:1.3">{{ __('one site') }}<br>{{ __('1 year of updates') }}</span>@endunless
                    <a class="btn btn-primary" href="{{ route('admin.marketplace.show', $featured->slug) }}">{{ $featured->isInstalled() ? __('View') : ($featured->isFree() ? __('Install') : __('Buy and install')) }}</a>
                    @if ($featured->demoUrl)<a class="btn" href="{{ $featured->demoUrl }}" target="_blank" rel="noopener" style="background:rgb(255 255 255 / .08);border-color:rgb(255 255 255 / .16);color:#fff">{{ __('Live demo') }}</a>@endif
                </div>
            </div>
            <div class="market-hero-shot">
                @if ($featured->screenshots)
                    <img src="{{ $featured->screenshots[0] }}" alt="{{ __(':name screenshot', ['name' => $featured->name]) }}" loading="lazy">
                @endif
            </div>
        </section>
    @endif

    @if ($tab !== 'installed' && $tab !== 'updates')
        @php $base = array_filter(['tab' => $tab === 'discover' ? null : $tab, 'q' => $search ?: null]); @endphp
        <div class="filters">
            <a class="chip" href="{{ route('admin.marketplace.index', $base + array_filter(['price' => $price ?: null])) }}" @if ($category === '') aria-current="true" @endif>{{ __('All') }}</a>
            @foreach ($categories as $name)
                <a class="chip" href="{{ route('admin.marketplace.index', $base + array_filter(['category' => $name, 'price' => $price ?: null])) }}" @if ($category === $name) aria-current="true" @endif>{{ $name }}</a>
            @endforeach
            <span style="flex:1"></span>
            <a class="chip" href="{{ route('admin.marketplace.index', $base + array_filter(['category' => $category ?: null, 'price' => $price === 'free' ? null : 'free'])) }}" @if ($price === 'free') aria-current="true" @endif>{{ __('Free') }}</a>
            <a class="chip" href="{{ route('admin.marketplace.index', $base + array_filter(['category' => $category ?: null, 'price' => $price === 'paid' ? null : 'paid'])) }}" @if ($price === 'paid') aria-current="true" @endif>{{ __('Paid') }}</a>
        </div>
    @endif

    @if ($items->isEmpty())
        <section class="card">
            <div class="empty">
                <strong>{{ $tab === 'updates' ? __('Everything is up to date') : __('Nothing here yet') }}</strong>
                {{ $tab === 'updates' ? __('New versions of what you installed show up here.') : __('Try another tab or search.') }}
            </div>
        </section>
    @else
        <div class="market-grid">
            @foreach ($items as $listing)
                @include('admin.marketplace.partials.card', ['listing' => $listing])
            @endforeach
        </div>
    @endif

    <p class="muted" style="margin:0;font-size:.85rem">{{ __('Want to sell your own theme or extension? Developers keep :share% of every sale.', ['share' => 83]) }} <a href="{{ config('nuvabill.marketplace.url') }}/developers" target="_blank" rel="noopener">{{ __('Become a developer') }}</a></p>
</x-layouts.admin>
