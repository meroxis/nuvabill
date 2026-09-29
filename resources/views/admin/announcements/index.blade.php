<x-layouts.admin :title="__('Announcements')">
    <div class="page-head">
        <div>
            <h1>{{ __('Announcements') }}</h1>
            <p>{{ __('News for your clients. The newest shows on the client area dashboard for two weeks.') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.announcements.create') }}"><x-icon name="plus" />{{ __('New announcement') }}</a>
    </div>

    @include('admin.support.nav')

    <div class="grid-2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start">
        <section class="card card-flush">
            @if ($announcements->isEmpty())
                <div class="empty"><strong>{{ __('No announcements yet') }}</strong>{{ __('Tell clients about new plans, price changes or planned work.') }}</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Date') }}</th>@if ($languages !== [])<th>{{ __('Languages') }}</th>@endif<th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($announcements as $announcement)
                        <tr>
                            <td><a class="row-link" href="{{ route('admin.announcements.edit', $announcement) }}">{{ $announcement->title }}</a></td>
                            <td class="num" style="white-space:nowrap">{{ $announcement->published_at?->translatedFormat('d M Y') ?? '—' }}</td>
                            @if ($languages !== [])<td class="num">{{ count($announcement->translatedLocales()) + 1 }} / {{ count($languages) + 1 }}</td>@endif
                            <td>
                                @if ($announcement->isPublic())
                                    <x-pill tone="good">{{ __('Published') }}</x-pill>
                                @elseif ($announcement->is_published)
                                    <x-pill tone="info">{{ __('Scheduled') }}</x-pill>
                                @else
                                    <x-pill>{{ __('Draft') }}</x-pill>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>

        <form method="POST" action="{{ route('admin.announcements.settings') }}" class="card" style="display:grid;gap:1rem">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('On your site') }}</h2></div>
            <x-checkbox name="enabled" :label="__('Show announcements')" :help="__('On the announcements page, in its RSS feed and on the client area dashboard.')" :checked="setting('announcements.enabled')" />
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                @if (setting('announcements.enabled'))
                    <a class="btn" href="{{ route('announcements.index') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open') }}</a>
                @endif
            </div>
        </form>
    </div>

    {{ $announcements->links() }}
</x-layouts.admin>
