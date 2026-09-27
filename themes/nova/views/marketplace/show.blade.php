@extends('theme::layouts.app')

@section('title', $item->name)
@section('description', $item->summary)

@section('content')
    <div class="page-title">
        <div style="display:flex;gap:16px;align-items:center">
            @include('theme::marketplace.partials.icon', ['item' => $item, 'size' => 'lg'])
            <div>
                <p class="eyebrow"><a href="{{ route('marketplace.index') }}">{{ __('Marketplace') }}</a> · {{ $item->type->label() }}</p>
                <h1 style="margin-top:.2rem">{{ $item->name }}</h1>
                <p>{{ __('by :name', ['name' => $item->developer->name]) }} · {{ __('Version :version', ['version' => $item->latestVersion?->version]) }} · <span style="color:var(--nb-accent);font-weight:700">{{ __('Reviewed and signed') }}</span></p>
            </div>
        </div>
    </div>

    <div class="two-col">
        <div style="display:grid;gap:16px">
            @foreach ((array) $item->screenshots as $index => $file)
                <img class="mkt-shot" src="{{ route('marketplace.media', [$item->slug, basename($file)]) }}" alt="{{ __(':name screenshot :number', ['name' => $item->name, 'number' => $index + 1]) }}" @if ($index > 0) loading="lazy" @endif>
            @endforeach

            @if ($item->description)
                <section class="card" style="display:grid;gap:.6rem">
                    <h2 style="font-size:1.05rem">{{ __('About :name', ['name' => $item->name]) }}</h2>
                    <div style="line-height:1.65">{!! nl2br(e($item->description)) !!}</div>
                </section>
            @endif

            @if ($item->permissions)
                <section class="card" style="display:grid;gap:.7rem">
                    <h2 style="font-size:1.05rem">{{ __('What it can do on your site') }}</h2>
                    @foreach ($item->permissions as $code)
                        @php $permission = \App\Marketplace\Permissions::describe($code); @endphp
                        <div class="mkt-perm"><x-icon name="check" /><span><b>{{ $permission['title'] }}</b>@if ($permission['text'])<br><span class="muted">{{ $permission['text'] }}</span>@endif</span></div>
                    @endforeach
                </section>
            @endif
        </div>

        <aside class="card summary" style="gap:1rem">
            @if ($item->isFree())
                <div class="mkt-big-price" data-free>{{ __('Free') }}</div>
                <p class="muted" style="margin:0">{{ __('Install it in one click from your Nuvabill: Admin → Marketplace → :name.', ['name' => $item->name]) }}</p>
            @elseif ($price)
                <div style="display:flex;align-items:baseline;gap:10px"><span class="mkt-big-price">{{ money($item->price, $item->currency) }}</span><span class="muted">{{ __('one time, one site') }}</span></div>
                <p class="muted" style="margin:0">{{ $item->update_price ? __('Includes 1 year of updates. After that, updates are :price a year. It keeps working if you stop.', ['price' => money($item->update_price, $item->currency)]) : __('Includes updates.') }}</p>
                <form method="POST" action="{{ route('cart.store') }}" style="display:grid;gap:.8rem">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $item->product_id }}">
                    <input type="hidden" name="billing_cycle" value="{{ $price->billing_cycle->value }}">
                    <x-input name="domain" :label="__('Website address for this license')" placeholder="billing.yourhost.com" required autocomplete="off" :help="__('The address of your Nuvabill. You can move the key later from your account.')" />
                    <button class="btn btn-primary btn-block" type="submit"><x-icon name="cart" />{{ __('Buy :name', ['name' => $item->name]) }}</button>
                </form>
            @endif

            @if ($item->demo_url)
                <a class="btn btn-block" href="{{ $item->demo_url }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open the live demo') }}</a>
            @endif

            <dl class="dl" style="margin:0">
                <dt>{{ __('Needs') }}</dt><dd>{{ __('Nuvabill :version', ['version' => str_replace('>=', '', (string) ($item->latestVersion?->manifest['requires'] ?? '0.3.0')).'+']) }}</dd>
                @if ($item->category)<dt>{{ __('Category') }}</dt><dd>{{ $item->category }}</dd>@endif
                <dt>{{ __('Installs') }}</dt><dd>{{ number_format($item->installs_count) }}</dd>
                @if ($item->docs_url)<dt>{{ __('Help') }}</dt><dd><a href="{{ $item->docs_url }}" target="_blank" rel="noopener">{{ __('Read the guide') }}</a></dd>@endif
            </dl>
        </aside>
    </div>
@endsection
