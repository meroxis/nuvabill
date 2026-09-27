{{-- The item's icon: the store's glyph in its colours, or a default for its type. --}}
@php
    $fallback = match ($listing->type) {
        \App\Marketplace\PackageType::Theme => 'brush',
        \App\Marketplace\PackageType::OrderForm => 'zap',
        \App\Marketplace\PackageType::Gateway => 'card',
        \App\Marketplace\PackageType::Server => 'server',
        \App\Marketplace\PackageType::Registrar => 'globe',
        default => 'puzzle',
    };
@endphp
<span class="market-icon {{ $size ?? '' }}" style="background:{{ $listing->icon['bg'] }};color:{{ $listing->icon['fg'] }}" aria-hidden="true">
    @if ($listing->icon['glyph'] !== '')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $listing->icon['glyph'] }}"/></svg>
    @else
        <x-icon :name="$fallback" />
    @endif
</span>
