<x-layouts.admin :title="__('Activity log')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <section class="card card-flush">
        <div class="card-header">
            <h2>{{ __('Activity log') }}</h2>
            <form method="GET" action="{{ route('admin.settings.activity') }}">
                <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search') }}" aria-label="{{ __('Search activity') }}" style="width:220px">
            </form>
        </div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('When') }}</th><th>{{ __('What happened') }}</th><th>{{ __('Who') }}</th><th>{{ __('Client') }}</th><th>{{ __('IP') }}</th></tr></thead>
            <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td style="white-space:nowrap" class="num">{{ $entry->created_at->format('d M Y H:i') }}</td>
                    <td>{{ $entry->description }}</td>
                    <td>{{ $entry->actorName() }}</td>
                    <td>@if ($entry->client)<a href="{{ route('admin.clients.show', $entry->client) }}">{{ $entry->client->name }}</a>@endif</td>
                    <td class="mono faint">{{ $entry->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">{{ __('Nothing logged yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $entries->links() }}
    </section>
</x-layouts.admin>
