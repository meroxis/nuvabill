@extends('theme::layouts.app')

@section('title', __('Search the knowledge base'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('kb.index') }}">{{ __('Knowledge base') }}</a></p>
            <h1 style="margin-top:.3rem">{{ __('Results for “:query”', ['query' => $query]) }}</h1>
        </div>
    </div>

    <div style="margin-bottom:16px">@include('theme::knowledgebase.search-form')</div>

    <section class="card card-flush">
        @if ($articles->isEmpty())
            <div class="empty">
                <strong>{{ __('No articles found') }}</strong>
                {{ __('Try other words, or ask us.') }}
                <a href="{{ auth('web')->check() ? route('client.tickets.create') : route('client.login') }}">{{ __('Open a support ticket') }}</a>
            </div>
        @else
            <ul class="help-list">
                @foreach ($articles as $article)
                    <li><a href="{{ $article->url() }}"><strong><bdi>{{ $article->localized('title') }}</bdi></strong><span><bdi>{{ $article->category->localized('title') }}</bdi> · {{ $article->excerpt(140) }}</span></a></li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
