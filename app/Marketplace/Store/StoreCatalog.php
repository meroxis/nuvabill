<?php

namespace App\Marketplace\Store;

use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use Carbon\CarbonInterface;

/**
 * The public catalog: live items with their newest approved version, as the API sends them.
 */
class StoreCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function items(?string $nuvabillVersion = null): array
    {
        return MarketplaceItem::query()
            ->live()
            ->with('developer', 'latestVersion')
            ->orderByDesc('is_featured')
            ->orderByDesc('installs_count')
            ->orderBy('name')
            ->get()
            ->map(fn (MarketplaceItem $item): array => $this->present($item, $nuvabillVersion))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(MarketplaceItem $item, ?string $nuvabillVersion = null): array
    {
        $version = $item->latestVersion;
        $requires = (string) ($version?->manifest['requires'] ?? '');

        return [
            'slug' => $item->slug,
            'type' => $item->type->value,
            'name' => $item->name,
            'summary' => (string) $item->summary,
            'description' => (string) $item->description,
            'category' => (string) $item->category,
            'developer' => ['name' => $item->developer->name, 'verified' => $item->developer->is_verified || $item->developer->is_official],
            'price' => $item->price,
            'update_price' => $item->update_price,
            'currency' => $item->currency,
            'version' => $version?->version,
            'requires' => $requires,
            'compatible' => $nuvabillVersion === null || $this->compatible($requires, $nuvabillVersion),
            'permissions' => array_values((array) ($item->permissions ?? [])),
            'icon' => $item->icon ?: null,
            'screenshots' => collect($item->screenshots ?? [])->map(fn (string $file): string => route('marketplace.media', [$item->slug, basename($file)]))->values()->all(),
            'demo_url' => $item->demo_url,
            'docs_url' => $item->docs_url,
            'url' => route('marketplace.show', $item),
            'featured' => $item->is_featured,
            'installs' => $item->installs_count,
            'updated_at' => $version?->released_at?->toIso8601String(),
        ];
    }

    /**
     * The newest approved version that works with the site's Nuvabill and, for a license whose
     * updates ended, was released before they ended.
     */
    public function newestFor(MarketplaceItem $item, ?string $nuvabillVersion, ?CarbonInterface $updatesUntil = null): ?MarketplaceVersion
    {
        return $item->versions()
            ->where('status', MarketplaceVersion::STATUS_APPROVED)
            ->when($updatesUntil !== null, fn ($query) => $query->where('released_at', '<=', $updatesUntil->copy()->endOfDay()))
            ->get()
            ->filter(fn (MarketplaceVersion $version): bool => $nuvabillVersion === null || $this->compatible((string) ($version->manifest['requires'] ?? ''), $nuvabillVersion))
            ->sort(fn (MarketplaceVersion $a, MarketplaceVersion $b): int => version_compare($b->version, $a->version))
            ->first();
    }

    private function compatible(string $requires, string $nuvabillVersion): bool
    {
        return $requires === '' || ! str_starts_with($requires, '>=') || version_compare($nuvabillVersion, trim(substr($requires, 2)), '>=');
    }
}
