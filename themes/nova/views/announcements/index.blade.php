@extends('theme::layouts.app')

@section('title', __('Announcements'))

@section('content')
    <div class="page-title">
        <div>
            <h1>{{ __('Announcements') }}</h1>
            <p>{{ __('News from :company: new services, changes and planned work.', ['company' => setting('company.name')]) }}</p>
        </div>
        <a class="btn btn-sm" href="{{ route('announcements.feed') }}"><x-icon name="activity" />{{ __('RSS feed') }}</a>
    </div>

    <section class="card card-flush">
        @if ($announcements->isEmpty())
            <div class="empty">{{ __('No announcements yet.') }}</div>
        @else
            <ul class="help-list">
                @foreach ($announcements as $announcement)
                    <li>
                        <a href="{{ route('announcements.show', $announcement->slug) }}">
                            <span class="news-date">{{ $announcement->published_at->translatedFormat('d M Y') }}</span>
                            <strong><bdi>{{ $announcement->localized('title') }}</bdi></strong>
                            <span>{{ $announcement->excerpt(180) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{ $announcements->links() }}
@endsection
