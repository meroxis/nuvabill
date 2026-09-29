@extends('theme::layouts.app')

@section('title', $announcement->localized('title'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('announcements.index') }}">{{ __('Announcements') }}</a> · {{ $announcement->published_at->translatedFormat('d M Y') }}</p>
            <h1 style="margin-top:.3rem"><bdi>{{ $announcement->localized('title') }}</bdi></h1>
        </div>
    </div>

    <article class="card" style="max-width:820px">
        <div class="prose" dir="auto">{!! $announcement->html() !!}</div>
    </article>

    @if ($newer || $older)
        <nav style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:16px;max-width:820px" aria-label="{{ __('More announcements') }}">
            @if ($older)<a class="btn btn-sm" href="{{ route('announcements.show', $older->slug) }}"><x-icon name="chevron-left" /><bdi>{{ $older->localized('title') }}</bdi></a>@else<span></span>@endif
            @if ($newer)<a class="btn btn-sm" href="{{ route('announcements.show', $newer->slug) }}"><bdi>{{ $newer->localized('title') }}</bdi><x-icon name="chevron-right" /></a>@endif
        </nav>
    @endif
@endsection
