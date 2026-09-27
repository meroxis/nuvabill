{{-- HTML that switched-on add-ons (for example a live chat widget) add to the page head. --}}
@props(['area' => 'client'])
@if (\App\Support\Installation::isInstalled())
{!! app(\App\Extensions\ExtensionManager::class)->headHtml($area) !!}
@endif
