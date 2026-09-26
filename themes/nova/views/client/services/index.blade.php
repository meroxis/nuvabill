@extends('theme::layouts.app')

@section('title', __('Services'))

@section('content')
    <div class="page-title">
        <div><h1>{{ __('Your services') }}</h1></div>
        <a class="btn btn-primary" href="{{ route('store.index') }}"><x-icon name="plus" />{{ __('Order more') }}</a>
    </div>

    @if ($services->isEmpty())
        <div class="card empty"><strong>{{ __('No services yet') }}</strong>{{ __('When you order hosting, it appears here.') }}</div>
    @else
        <div class="service-grid">
            @foreach ($services as $service)
                @include('theme::client.services.card', ['service' => $service])
            @endforeach
        </div>
        {{ $services->links() }}
    @endif
@endsection
