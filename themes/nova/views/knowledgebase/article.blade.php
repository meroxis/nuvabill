@extends('theme::layouts.app')

@section('title', $article->localized('title'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('kb.index') }}">{{ __('Knowledge base') }}</a> / <a href="{{ route('kb.category', $category->slug) }}"><bdi>{{ $category->localized('title') }}</bdi></a></p>
            <h1 style="margin-top:.3rem"><bdi>{{ $article->localized('title') }}</bdi></h1>
        </div>
    </div>

    <div class="help-layout">
        <article class="card">
            <div class="prose" dir="auto">{!! $article->html() !!}</div>

            <div class="helpful" id="helpful">
                @if (session('kb_thanks') || $voted)
                    <span class="muted">{{ __('Thank you for your feedback.') }}</span>
                @else
                    <span>{{ __('Was this article helpful?') }}</span>
                    <form method="POST" action="{{ route('kb.vote', [$category->slug, $article->slug]) }}">
                        @csrf
                        <button class="btn btn-sm" type="submit" name="helpful" value="1">{{ __('Yes') }}</button>
                        <button class="btn btn-sm" type="submit" name="helpful" value="0">{{ __('No') }}</button>
                    </form>
                @endif
            </div>
        </article>

        <aside style="display:grid;gap:14px">
            @if ($related->isNotEmpty())
                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('More in this category') }}</h2></div>
                    <ul class="help-list">
                        @foreach ($related as $item)
                            <li><a href="{{ route('kb.article', [$category->slug, $item->slug]) }}"><strong><bdi>{{ $item->localized('title') }}</bdi></strong></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <section class="card">
                <p style="margin:0 0 .7rem">{{ __('Still need help?') }}</p>
                <a class="btn btn-primary" href="{{ auth('web')->check() ? route('client.tickets.create') : route('client.login') }}">{{ __('Open a support ticket') }}</a>
            </section>
        </aside>
    </div>
@endsection
