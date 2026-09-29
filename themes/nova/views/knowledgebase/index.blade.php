@extends('theme::layouts.app')

@section('title', __('Knowledge base'))

@section('content')
    <div class="help-hero">
        <h1>{{ __('How can we help?') }}</h1>
        <p>{{ __('Answers to common questions about our services, billing and your account.') }}</p>
        @include('theme::knowledgebase.search-form')
    </div>

    @if ($categories->isEmpty())
        <div class="card empty">{{ __('No articles yet.') }}</div>
    @else
        <div class="help-grid">
            @foreach ($categories as $category)
                <a class="help-category" href="{{ route('kb.category', $category->slug) }}">
                    <strong><bdi>{{ $category->localized('title') }}</bdi></strong>
                    @if (filled($category->localized('body')))<span><bdi>{{ $category->localized('body') }}</bdi></span>@endif
                    <span>{{ trans_choice(':count article|:count articles', $category->articles_count, ['count' => $category->articles_count]) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @if ($popular->isNotEmpty())
        <section class="card card-flush" style="margin-top:18px">
            <div class="card-header"><h2>{{ __('Popular articles') }}</h2></div>
            <ul class="help-list">
                @foreach ($popular as $article)
                    <li><a href="{{ $article->url() }}"><strong><bdi>{{ $article->localized('title') }}</bdi></strong><span>{{ $article->excerpt(120) }}</span></a></li>
                @endforeach
            </ul>
        </section>
    @endif

    <p class="muted" style="margin-top:18px">{{ __('Did not find the answer?') }} <a href="{{ auth('web')->check() ? route('client.tickets.create') : route('client.login') }}">{{ __('Open a support ticket') }}</a></p>
@endsection
