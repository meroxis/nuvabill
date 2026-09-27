@extends('theme::layouts.app')

@section('title', __('Marketplace'))
@section('description', __('Themes, order forms and extensions for Nuvabill. Reviewed, signed and installed in one click.'))

@section('content')
    <section class="mkt-hero">
        <div class="mkt-hero-text">
            <span class="mkt-tag"><span class="mkt-dot"></span>{{ __('For Nuvabill 0.3 and newer') }}</span>
            <h1>{{ __('The Nuvabill Marketplace') }}</h1>
            <p>{{ __('Themes and extensions made for Nuvabill. Every one is reviewed by our team and signed, then installs in one click from your admin area.') }}</p>
            <div class="mkt-hero-actions">
                <a class="btn btn-primary" href="#browse">{{ __('Browse the marketplace') }}</a>
                <a class="btn mkt-glass" href="{{ route('marketplace.developers') }}">{{ __('Sell your work') }}</a>
            </div>
        </div>
        @if ($featured->first()?->screenshots)
            <div class="mkt-hero-shot" aria-hidden="true">
                <img src="{{ route('marketplace.media', [$featured->first()->slug, basename($featured->first()->screenshots[0])]) }}" alt="">
            </div>
        @endif
    </section>

    <div class="mkt-promises">
        <div><x-icon name="shield" /><span><b>{{ __('Reviewed by people') }}</b>{{ __('Our team checks every item') }}</span></div>
        <div><x-icon name="lock" /><span><b>{{ __('Signed packages') }}</b>{{ __('Nobody can change them') }}</span></div>
        <div><x-icon name="download" /><span><b>{{ __('One-click install') }}</b>{{ __('From your admin area') }}</span></div>
        <div><x-icon name="card" /><span><b>{{ __('Card or wallet') }}</b>{{ __('Local and international') }}</span></div>
    </div>

    @if ($featured->isNotEmpty())
        <div class="mkt-section-head"><h2>{{ __('Featured') }}</h2><span class="muted">{{ __('Prices in US dollars. Paid by card or wallet.') }}</span></div>
        <div class="mkt-featured">
            @foreach ($featured as $item)
                <article class="mkt-feature">
                    <a class="mkt-feature-shot" href="{{ route('marketplace.show', $item) }}" tabindex="-1" aria-hidden="true">
                        @if ($item->screenshots)
                            <img src="{{ route('marketplace.media', [$item->slug, basename($item->screenshots[0])]) }}" alt="" loading="lazy">
                        @endif
                    </a>
                    <div class="mkt-feature-body">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:10px"><h3><a href="{{ route('marketplace.show', $item) }}">{{ $item->name }}</a></h3><span class="muted">{{ $item->type->label() }} · {{ __('by :name', ['name' => $item->developer->name]) }}</span></div>
                        <p>{{ $item->summary }}</p>
                        <div class="mkt-feature-foot">
                            <b class="mkt-big-price">{{ $item->isFree() ? __('Free') : money($item->price, $item->currency) }}</b>
                            @unless ($item->isFree())<span class="muted" style="font-size:.8rem;line-height:1.3">{{ __('one site') }}<br>{{ __('1 year of updates') }}</span>@endunless
                            <span style="flex:1"></span>
                            @if ($item->demo_url)<a class="btn" href="{{ $item->demo_url }}" target="_blank" rel="noopener">{{ __('Live demo') }}</a>@endif
                            <a class="btn btn-primary" href="{{ route('marketplace.show', $item) }}">{{ $item->isFree() ? __('Get it') : __('Buy :name', ['name' => $item->name]) }}</a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <div class="mkt-filters" id="browse">
        <a class="mkt-chip" href="{{ route('marketplace.index') }}#browse" @if (! $type && $category === '' && $price === '') aria-current="true" @endif>{{ __('All') }}</a>
        @foreach ([\App\Marketplace\PackageType::Theme, \App\Marketplace\PackageType::OrderForm] as $option)
            <a class="mkt-chip" href="{{ route('marketplace.index', ['type' => $option->value]) }}#browse" @if ($type === $option) aria-current="true" @endif>{{ $option === \App\Marketplace\PackageType::Theme ? __('Themes') : __('Order forms') }}</a>
        @endforeach
        @foreach ($categories as $name)
            <a class="mkt-chip" href="{{ route('marketplace.index', ['category' => $name]) }}#browse" @if ($category === $name) aria-current="true" @endif>{{ $name }}</a>
        @endforeach
        <span style="flex:1"></span>
        <a class="mkt-chip" href="{{ route('marketplace.index', ['price' => 'free']) }}#browse" @if ($price === 'free') aria-current="true" @endif>{{ __('Free') }}</a>
        <a class="mkt-chip" href="{{ route('marketplace.index', ['price' => 'paid']) }}#browse" @if ($price === 'paid') aria-current="true" @endif>{{ __('Paid') }}</a>
    </div>

    @if ($items->isEmpty())
        <div class="card empty"><strong>{{ __('Nothing here yet') }}</strong>{{ __('Try another filter.') }}</div>
    @else
        <div class="mkt-grid">
            @foreach ($items as $item)
                @include('theme::marketplace.partials.card', ['item' => $item])
            @endforeach
        </div>
    @endif

    <section class="mkt-sell">
        <div>
            <span class="eyebrow">{{ __('For developers') }}</span>
            <h2>{{ __('Sell your themes and extensions here') }}</h2>
            <p>{{ __('Send us your work. Our team reviews it, signs it and lists it. We take the payments and send the license keys. You keep :share% of every sale, paid every month.', ['share' => $share]) }}</p>
            <a class="btn btn-primary" href="{{ route('marketplace.developers') }}">{{ __('Become a developer') }}</a>
        </div>
    </section>
@endsection
