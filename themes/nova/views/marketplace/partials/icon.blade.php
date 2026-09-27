{{-- A marketplace item's icon tile: its own glyph and colours, or a default for its type. --}}
@php
    $icon = (array) ($item->icon ?? []);
    $bg = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($icon['bg'] ?? '')) ? $icon['bg'] : '#EEF2F6';
    $fg = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($icon['fg'] ?? '')) ? $icon['fg'] : '#0F1B2D';
    $glyph = preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9 .,\-]+$/', (string) ($icon['glyph'] ?? '')) ? $icon['glyph'] : null;
    $fallback = match ($item->type) {
        \App\Marketplace\PackageType::Theme => 'brush',
        \App\Marketplace\PackageType::OrderForm => 'zap',
        \App\Marketplace\PackageType::Gateway => 'card',
        \App\Marketplace\PackageType::Server => 'server',
        \App\Marketplace\PackageType::Registrar => 'globe',
        default => 'puzzle',
    };
@endphp
<span class="mkt-icon {{ $size ?? '' }}" style="background:{{ $bg }};color:{{ $fg }}" aria-hidden="true">
    @if ($glyph)
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $glyph }}"/></svg>
    @else
        <x-icon :name="$fallback" />
    @endif
</span>
