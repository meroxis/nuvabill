@extends('theme::layouts.app')

@section('title', $group->name)

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('store.index') }}">{{ __('Store') }}</a></p>
            <h1 style="margin-top:.3rem">{{ $group->name }}</h1>
            @if ($group->description)<p>{{ $group->description }}</p>@endif
        </div>
    </div>

    @if ($products->isEmpty())
        <div class="card empty">{{ __('Nothing here yet.') }}</div>
    @else
        <div class="plans">
            @foreach ($products as $product)
                @include('theme::store.partials.plan', ['product' => $product, 'group' => $group])
            @endforeach
        </div>
    @endif
@endsection
