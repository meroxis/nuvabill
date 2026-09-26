@extends('theme::layouts.app')

@section('title', __('Store'))

@section('content')
    <section class="hero">
        <p class="eyebrow">{{ setting('company.name') }}</p>
        <h1>{{ __('Hosting that is ready in minutes') }}</h1>
        <p>{{ __('Pick a plan, pay online, and your account is set up automatically.') }}</p>
    </section>

    @forelse ($groups as $group)
        <section style="display:grid;gap:14px">
            <div class="page-title">
                <div>
                    <h2 style="font-size:1.35rem">{{ $group->name }}</h2>
                    @if ($group->description)<p>{{ $group->description }}</p>@endif
                </div>
                <a class="btn btn-sm" href="{{ route('store.group', $group) }}">{{ __('See all') }}<x-icon name="chevron-right" /></a>
            </div>
            <div class="plans">
                @foreach ($group->products as $product)
                    @include('theme::store.partials.plan', ['product' => $product, 'group' => $group])
                @endforeach
            </div>
        </section>
    @empty
        <div class="card empty"><strong>{{ __('The store opens soon') }}</strong>{{ __('No products are for sale yet.') }}</div>
    @endforelse
@endsection
