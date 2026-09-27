<x-layouts.admin :title="__('Marketplace items')">
    <div class="page-head"><div><h1>{{ __('Marketplace store') }}</h1></div></div>
    @include('admin.store.nav')

    <section class="card card-flush">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Developer') }}</th><th>{{ __('Version') }}</th><th class="end">{{ __('Price') }}</th><th class="end">{{ __('Sales') }}</th><th class="end">{{ __('Installs') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse ($items as $item)
                <tr>
                    <td><a class="row-link" href="{{ route('admin.store.items.edit', $item) }}">{{ $item->name }}</a>@if ($item->is_featured) <x-pill tone="info">{{ __('Featured') }}</x-pill>@endif<div class="faint" style="font-size:.8rem">{{ $item->type->label() }} · {{ $item->slug }}</div></td>
                    <td>{{ $item->developer->name }}</td>
                    <td class="mono">{{ $item->latestVersion?->version ?? '—' }}</td>
                    <td class="end num">{{ $item->isFree() ? __('Free') : money($item->price, $item->currency) }}</td>
                    <td class="end num">{{ number_format($item->sales_count) }}</td>
                    <td class="end num">{{ number_format($item->installs_count) }}</td>
                    <td>@if ($item->isLive())<x-pill tone="good">{{ __('Live') }}</x-pill>@elseif ($item->status === 'hidden')<x-pill>{{ __('Hidden') }}</x-pill>@else<x-pill tone="info">{{ __('Draft') }}</x-pill>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty">{{ __('No items yet.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $items->links() }}
    </section>
</x-layouts.admin>
