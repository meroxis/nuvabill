@if ($item->isLive() && (! $version || $version->status === \App\Models\MarketplaceVersion::STATUS_APPROVED))
    <x-pill tone="good">{{ __('Live') }}</x-pill>
@elseif ($version?->status === \App\Models\MarketplaceVersion::STATUS_CHANGES)
    <x-pill tone="warn">{{ __('Changes asked') }}</x-pill>
@elseif ($version?->status === \App\Models\MarketplaceVersion::STATUS_REJECTED)
    <x-pill tone="crit">{{ __('Not accepted') }}</x-pill>
@elseif ($version?->status === \App\Models\MarketplaceVersion::STATUS_PENDING)
    <x-pill tone="info">{{ __('In review') }}</x-pill>
@else
    <x-pill>{{ __('Draft') }}</x-pill>
@endif
