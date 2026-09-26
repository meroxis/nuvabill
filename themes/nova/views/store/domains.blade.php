@extends('theme::layouts.app')

@section('title', $query !== '' ? __('Domains for ":query"', ['query' => $query]) : __('Find a domain'))
@section('description', __('Search and register domain names with :company.', ['company' => setting('company.name')]))

@section('content')
    <section class="hero">
        <p class="eyebrow">{{ __('Domains') }}</p>
        <h1>{{ __('Find your domain name') }}</h1>
        <form method="GET" action="{{ route('store.domains') }}" class="domain-search" role="search">
            <input class="input" type="search" name="q" value="{{ $query }}" placeholder="{{ __('yourname.com') }}" aria-label="{{ __('Domain name') }}" required autofocus autocomplete="off" spellcheck="false">
            <button class="btn btn-primary" type="submit"><x-icon name="search" />{{ __('Search') }}</button>
        </form>
    </section>

    @if ($invalid)
        <div class="flash" data-tone="crit"><span>{{ __('Use only letters, numbers and dashes, for example my-shop.com.') }}</span></div>
    @endif

    @if ($results->isNotEmpty())
        <section class="card card-flush" aria-live="polite">
            <div class="domain-results">
                @foreach ($results as $result)
                    <div class="domain-row @if ($result['exact']) is-exact @endif">
                        <span class="name">{{ $result['domain'] }}</span>

                        @if ($result['tld'] === null)
                            <span class="muted">{{ __('We do not sell this extension.') }}</span>
                        @elseif ($result['available'] === false)
                            <x-pill tone="muted">{{ __('Taken') }}</x-pill>
                            <form method="POST" action="{{ route('cart.domains.store') }}" x-data="{ open: false }">
                                @csrf
                                <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                                <input type="hidden" name="action" value="transfer">
                                <button class="btn btn-sm" type="button" x-show="! open" @click="open = true">{{ __('Is it yours? Transfer it') }}</button>
                                <template x-if="open">
                                    <span style="display:flex;gap:8px;flex-wrap:wrap">
                                        @if ($result['tld']->epp_required)
                                            <input class="input" name="epp_code" placeholder="{{ __('Authorization (EPP) code') }}" aria-label="{{ __('Authorization (EPP) code') }}" required style="width:220px" autocomplete="off">
                                        @endif
                                        <button class="btn btn-sm btn-primary" type="submit">{{ __('Transfer for :price', ['price' => money($result['tld']->transfer_price, $currency)]) }}</button>
                                    </span>
                                </template>
                            </form>
                        @else
                            @if ($result['available'] === true)
                                <x-pill tone="good">{{ __('Available') }}</x-pill>
                            @else
                                <x-pill tone="warn">{{ __('Could not check') }}</x-pill>
                            @endif
                            <span class="price">{{ __(':price / year', ['price' => money($result['tld']->register_price, $currency)]) }}</span>
                            <form method="POST" action="{{ route('cart.domains.store') }}">
                                @csrf
                                <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                                <input type="hidden" name="action" value="register">
                                @if (count($result['tld']->yearOptions()) > 1)
                                    <select class="select" name="years" aria-label="{{ __('Years') }}" style="width:auto">
                                        @foreach ($result['tld']->yearOptions() as $years)
                                            <option value="{{ $years }}">{{ trans_choice(':count year|:count years', $years, ['count' => $years]) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button class="btn btn-sm btn-primary" type="submit"><x-icon name="cart" />{{ __('Add to cart') }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
        @error('domain')<div class="flash" data-tone="crit"><span>{{ $message }}</span></div>@enderror
        @error('epp_code')<div class="flash" data-tone="crit"><span>{{ $message }}</span></div>@enderror
    @endif

    @if ($prices->isNotEmpty())
        <section style="display:grid;gap:12px">
            <h2 style="font-size:1.2rem">{{ __('Prices per year') }}</h2>
            <div class="tld-list">
                @foreach ($prices as $price)
                    <div class="tld-chip">
                        <b>.{{ $price->tld }}</b>
                        <span>{{ __('Register :price', ['price' => money($price->register_price, $currency)]) }}</span>
                        <span>{{ __('Renew :price', ['price' => money($price->renew_price, $currency)]) }}</span>
                        <span>{{ __('Transfer :price', ['price' => money($price->transfer_price, $currency)]) }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @else
        <div class="card empty"><strong>{{ __('Domains are not for sale yet') }}</strong>{{ __('Please check back soon.') }}</div>
    @endif
@endsection
