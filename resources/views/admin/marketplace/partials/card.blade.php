{{-- One item in the marketplace grid. --}}
<a class="market-card" href="{{ route('admin.marketplace.show', $listing->slug) }}">
    <div style="display:flex;gap:12px;align-items:flex-start">
        @include('admin.marketplace.partials.icon', ['listing' => $listing])
        <div style="min-width:0">
            <b style="font-size:.95rem">{{ $listing->name }}</b>
            <div class="market-by">
                {{ $listing->type->label() }}@if ($listing->developer) · {{ __('by :name', ['name' => $listing->developer]) }}@endif
                @if ($listing->verified)<x-icon name="shield" />@endif
            </div>
        </div>
    </div>
    <p>{{ \Illuminate\Support\Str::limit($listing->summary, 120) }}</p>
    <div class="market-card-foot">
        @if ($listing->builtIn)
            <span class="muted" style="font-size:.85rem">{{ __('Built in') }}</span>
        @else
            <span class="market-price" @if ($listing->isFree()) data-free @endif>{{ $listing->priceLabel() }}</span>
        @endif
        @if ($listing->hasUpdate())
            <x-pill tone="info">{{ __('Update to :version', ['version' => $listing->version]) }}</x-pill>
        @elseif ($listing->isInstalled())
            <x-pill tone="good">{{ __('Installed') }}</x-pill>
        @elseif (! $listing->compatible)
            <x-pill tone="warn">{{ __('Needs a newer Nuvabill') }}</x-pill>
        @else
            <span class="btn btn-sm {{ $listing->isFree() ? '' : 'btn-primary' }}">{{ $listing->isFree() ? __('Install') : __('Buy') }}</span>
        @endif
    </div>
</a>
