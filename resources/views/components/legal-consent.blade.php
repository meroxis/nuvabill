@props(['action'])
{{-- "By creating an account, you agree to our Terms of service and Privacy policy." Only the links staff set are shown. --}}
@php
    $links = collect([
        (string) setting('orders.accept_terms_url') => __('Terms of service'),
        (string) setting('company.privacy_url') => __('Privacy policy'),
    ])->filter(fn (string $label, string $url): bool => $url !== '')
        ->map(fn (string $label, string $url): string => '<a href="'.e($url).'" target="_blank" rel="noopener">'.e($label).'</a>');
@endphp
@if ($links->isNotEmpty())
    <p {{ $attributes->merge(['class' => 'legal-consent']) }}>
        {!! __('By :action, you agree to our :links.', ['action' => e($action), 'links' => $links->implode(' '.e(__('and')).' ')]) !!}
    </p>
@endif
