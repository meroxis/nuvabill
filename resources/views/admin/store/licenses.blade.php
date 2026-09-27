<x-layouts.admin :title="__('Licenses')">
    <div class="page-head"><div><h1>{{ __('Marketplace store') }}</h1></div></div>
    @include('admin.store.nav')

    <form class="filters" method="GET" role="search">
        <input class="input" type="search" name="q" value="{{ $term }}" placeholder="{{ __('Key, site or client email') }}" aria-label="{{ __('Find a license') }}" style="max-width:320px">
    </form>

    <section class="card card-flush">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('License') }}</th><th>{{ __('Item') }}</th><th>{{ __('Client') }}</th><th>{{ __('Site') }}</th><th>{{ __('Updates until') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse ($licenses as $license)
                <tr>
                    <td><a class="row-link mono" href="{{ route('admin.store.licenses.show', $license) }}">{{ $license->publicId() }}</a><div class="faint mono" style="font-size:.78rem">…{{ substr($license->key, -9) }}</div></td>
                    <td>{{ $license->item->name }}</td>
                    <td>{{ $license->client->email }}</td>
                    <td>{{ $license->site ?? '—' }}</td>
                    <td>{{ $license->updates_until?->translatedFormat('d M Y') ?? '—' }}</td>
                    <td>@if ($license->isActive())<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill tone="crit">{{ __('Revoked') }}</x-pill>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty">{{ __('No licenses found.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $licenses->links() }}
    </section>
</x-layouts.admin>
