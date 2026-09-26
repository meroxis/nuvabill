@props(['form', 'always' => false])
{{-- The CAPTCHA widget for a form, when staff turned it on for that form. "always" shows it whenever a provider is set (settings check). --}}
@php
    $captcha = app(\App\Security\Captcha::class);
    $details = $always ? $captcha->details((string) setting('security.captcha_provider')) : ($captcha->protects($form) ? $captcha->details() : null);
@endphp
@if ($details && $captcha->siteKey() !== '')
    <div class="field captcha-field">
        <div class="{{ $details['class'] }}" data-sitekey="{{ $captcha->siteKey() }}" @if ($details['class'] === 'cf-turnstile') data-theme="auto" @endif></div>
        @error('captcha')<p class="error">{{ $message }}</p>@enderror
    </div>
    @once
        <script src="{{ $details['script'] }}" async defer></script>
    @endonce
@endif
