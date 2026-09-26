@props(['title', 'subtitle' => null, 'wide' => false])
{{-- Centered single-card layout for staff sign-in pages and the installer. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <title>{{ $title }} · {{ \App\Support\Branding::PRODUCT_NAME }}</title>
    <meta name="robots" content="noindex">
    @include('partials.head', ['assets' => ['resources/css/admin.css', 'resources/js/app.js']])
</head>
<body>
<main class="auth-page">
    <div class="auth-card" @if ($wide) style="width:min(640px,100%)" @endif>
        <div style="display:flex;align-items:center;gap:10px">
            <x-logo style="width:34px;height:34px;color:var(--nb-accent)" />
            <span style="font-family:var(--nb-font-display);font-weight:800;font-size:1.3rem;letter-spacing:-.02em">{{ \App\Support\Branding::PRODUCT_NAME }}</span>
        </div>
        <div class="card" style="padding:1.5rem;display:grid;gap:1.1rem">
            <div>
                <h1 style="font-size:1.4rem">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="muted" style="margin:.35rem 0 0;font-size:.92rem">{{ $subtitle }}</p>
                @endif
            </div>
            <x-flash :hide-errors="true" />
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="muted" style="font-size:.85rem;text-align:center">{{ $footer }}</div>
        @endisset
    </div>
</main>
</body>
</html>
