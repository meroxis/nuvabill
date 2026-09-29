@extends('theme::layouts.app')

@section('title', $category->localized('title'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('kb.index') }}">{{ __('Knowledge base') }}</a></p>
            <h1 style="margin-top:.3rem"><bdi>{{ $category->localized('title') }}</bdi></h1>
            @if (filled($category->localized('body')))<p><bdi>{{ $category->localized('body') }}</bdi></p>@endif
        </div>
    </div>

    <div style="margin-bottom:16px">@include('theme::knowledgebase.search-form')</div>

    <section class="card card-flush">
        @if ($articles->isEmpty())
            <div class="empty">{{ __('No articles yet.') }}</div>
        @else
            <ul class="help-list">
                @foreach ($articles as $article)
                    <li><a href="{{ route('kb.article', [$category->slug, $article->slug]) }}"><strong><bdi>{{ $article->localized('title') }}</bdi></strong><span>{{ $article->excerpt(140) }}</span></a></li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
