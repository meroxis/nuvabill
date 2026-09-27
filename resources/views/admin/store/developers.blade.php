<x-layouts.admin :title="__('Developers')">
    <div class="page-head"><div><h1>{{ __('Marketplace store') }}</h1><p>{{ __('Developers keep :share% of each sale unless they have their own share.', ['share' => $share]) }}</p></div></div>
    @include('admin.store.nav')

    <section class="card card-flush">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Developer') }}</th><th>{{ __('Client') }}</th><th class="end">{{ __('Items') }}</th><th class="end">{{ __('Share') }}</th><th class="end">{{ __('Owed') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse ($developers as $developer)
                <tr>
                    <td><a class="row-link" href="{{ route('admin.store.developers.edit', $developer) }}">{{ $developer->name }}</a>@if ($developer->is_official) <x-pill tone="info">{{ __('Official') }}</x-pill>@elseif ($developer->is_verified) <x-pill tone="good">{{ __('Verified') }}</x-pill>@endif</td>
                    <td>@if ($developer->client)<a href="{{ route('admin.clients.show', $developer->client) }}">{{ $developer->client->email }}</a>@else — @endif</td>
                    <td class="end num">{{ $developer->items_count }}</td>
                    <td class="end num">{{ $developer->share() }}%</td>
                    <td class="end num">{{ money($developer->balance($currency), $currency) }}</td>
                    <td>@if ($developer->isActive())<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill tone="crit">{{ __('Suspended') }}</x-pill>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty">{{ __('No developers yet.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $developers->links() }}
    </section>
</x-layouts.admin>
