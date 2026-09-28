@php
    $accent = (string) setting('branding.accent', '#0B7A70');
    $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) ? $accent : '#0B7A70';
@endphp
<style>
    :root {
        --nb-accent: {{ $accent }};
        --nb-accent-2: color-mix(in srgb, {{ $accent }} 82%, #000);
        --nb-accent-soft: color-mix(in srgb, {{ $accent }} 8%, #fff);
        --nb-accent-ink: #fff;
    }
    @media (prefers-color-scheme: dark) {
        :root:not([data-theme='light']) {
            --nb-accent: color-mix(in srgb, {{ $accent }} 72%, #fff);
            --nb-accent-2: color-mix(in srgb, {{ $accent }} 55%, #fff);
            --nb-accent-soft: color-mix(in srgb, {{ $accent }} 24%, #121b1c);
            --nb-accent-ink: #0b1213;
        }
    }
    :root[data-theme='dark'] {
        --nb-accent: color-mix(in srgb, {{ $accent }} 72%, #fff);
        --nb-accent-2: color-mix(in srgb, {{ $accent }} 55%, #fff);
        --nb-accent-soft: color-mix(in srgb, {{ $accent }} 24%, #121b1c);
        --nb-accent-ink: #0b1213;
    }
</style>
