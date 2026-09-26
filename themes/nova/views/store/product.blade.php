@extends('theme::layouts.app')

@section('title', $product->name)

@section('content')
    @php
        $features = collect(preg_split('/\R/', (string) $product->description))->map(fn ($line) => trim($line, " \t-*•"))->filter();
        $selectedCycle = old('billing_cycle', $prices->first()?->billing_cycle->value);
    @endphp

    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('store.group', $group) }}">{{ $group->name }}</a></p>
            <h1 style="margin-top:.3rem">{{ $product->name }}</h1>
        </div>
    </div>

    <div class="two-col">
        <form method="POST" action="{{ route('cart.store') }}" class="card" style="display:grid;gap:1.2rem">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">

            @if (! $inStock)
                <div class="flash" data-tone="warn">{{ __('This plan is sold out right now.') }}</div>
            @endif

            <fieldset style="border:0;padding:0;margin:0;display:grid;gap:.6rem">
                <legend class="label" style="margin-bottom:.5rem">{{ __('How often do you want to pay?') }}</legend>
                <div class="cycle-options">
                    @foreach ($prices as $price)
                        <label class="cycle-option">
                            <input type="radio" name="billing_cycle" value="{{ $price->billing_cycle->value }}" @checked($selectedCycle === $price->billing_cycle->value)>
                            <span>{{ $price->billing_cycle->label() }}</span>
                            <b>{{ $price->price === 0 ? __('Free') : money($price->price, $currency) }}</b>
                            @if ($price->setup_fee > 0)<span>{{ __('+ :fee setup', ['fee' => money($price->setup_fee, $currency)]) }}</span>@endif
                        </label>
                    @endforeach
                </div>
                @error('billing_cycle')<p class="error" style="color:var(--nb-crit);font-size:.85rem;margin:0">{{ $message }}</p>@enderror
            </fieldset>

            @if ($product->requires_domain)
                <x-input name="domain" :label="__('Your domain name')" placeholder="example.com" :help="__('The website address this hosting is for. You can use a domain you already own.')" required autocomplete="off" />
                @if ($sellsDomains)
                    <x-checkbox name="register_domain" :label="__('Also register this domain for me')" :checked="(bool) old('register_domain')" />
                @endif
            @endif

            @error('product_id')<div class="flash" data-tone="crit">{{ $message }}</div>@enderror

            <button class="btn btn-primary" type="submit" @disabled(! $inStock || $prices->isEmpty())><x-icon name="cart" />{{ __('Add to cart') }}</button>
        </form>

        <aside class="card" style="display:grid;gap:.8rem">
            <h2 style="font-size:1.05rem">{{ __('What you get') }}</h2>
            @if ($features->isNotEmpty())
                <ul class="features">
                    @foreach ($features as $feature)
                        <li>{{ $feature }}</li>
                    @endforeach
                </ul>
            @else
                <p class="muted" style="margin:0">{{ $product->type->label() }}</p>
            @endif
            <p class="muted" style="margin:0;font-size:.85rem">{{ __('Set up automatically after payment. Cancel any time.') }}</p>
        </aside>
    </div>
@endsection
