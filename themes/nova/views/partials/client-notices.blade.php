{{-- Network issues on the signed-in client's servers and the newest announcement, for the client area dashboard. Themes add it with @includeIf('theme::partials.client-notices'). --}}
@php
    $noticeClient = auth('web')->user();
    $noticeIncidents = $noticeClient ? app(\App\Support\NetworkStatus::class)->forClient($noticeClient) : collect();
    $noticeNews = setting('announcements.enabled')
        ? \App\Models\Announcement::query()->public()->withTranslation()->where('published_at', '>=', now()->subDays(14))->newestFirst()->first()
        : null;
@endphp
@if ($noticeIncidents->isNotEmpty() || $noticeNews)
    <div style="display:grid;gap:10px;margin-bottom:16px">
        @foreach ($noticeIncidents as $incident)
            @php
                $lastUpdate = $incident->updates->first();
            @endphp
            <div class="client-notice" data-tone="{{ $incident->isMaintenance() ? 'info' : ($incident->impact === \App\Models\NetworkIncident::IMPACT_MAJOR ? 'crit' : 'warn') }}" role="status">
                <x-icon :name="$incident->isMaintenance() ? 'clock' : 'alert'" />
                <div>
                    <strong><bdi>{{ $incident->title }}</bdi></strong>
                    @if ($incident->isUpcoming() && $incident->starts_at)
                        <span>{{ __('Planned for :date.', ['date' => $incident->starts_at->translatedFormat('d M Y H:i')]) }}</span>
                    @endif
                    @if ($lastUpdate)<span style="white-space:pre-line" dir="auto">{{ \Illuminate\Support\Str::limit($lastUpdate->message, 220) }}</span>@endif
                    <a href="{{ route('network.status') }}">{{ __('See the network status page') }}</a>
                </div>
            </div>
        @endforeach
        @if ($noticeNews)
            <div class="client-notice" data-tone="info">
                <x-icon name="bell" />
                <div>
                    <strong><bdi>{{ $noticeNews->localized('title') }}</bdi></strong>
                    <span>{{ $noticeNews->excerpt(160) }}</span>
                    <a href="{{ route('announcements.show', $noticeNews->slug) }}">{{ __('Read more') }}</a>
                </div>
            </div>
        @endif
    </div>
@endif
