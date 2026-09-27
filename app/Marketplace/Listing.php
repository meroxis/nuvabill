<?php

namespace App\Marketplace;

use App\Models\MarketplaceInstall;

/**
 * One theme, order form or extension as the admin marketplace shows it: from the store's
 * catalog, from what is installed here, or both.
 */
final readonly class Listing
{
    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $screenshots
     * @param  array{glyph: string, bg: string, fg: string}  $icon
     */
    public function __construct(
        public string $slug,
        public PackageType $type,
        public string $name,
        public string $summary = '',
        public string $description = '',
        public string $category = '',
        public string $developer = '',
        public bool $verified = false,
        public int $price = 0,
        public int $updatePrice = 0,
        public string $currency = 'USD',
        public ?string $version = null,
        public bool $compatible = true,
        public string $requires = '',
        public array $permissions = [],
        public array $screenshots = [],
        public array $icon = ['glyph' => '', 'bg' => '#EEF2F6', 'fg' => '#0F1B2D'],
        public ?string $storeUrl = null,
        public ?string $demoUrl = null,
        public bool $featured = false,
        public ?MarketplaceInstall $install = null,
        public bool $builtIn = false,
        public ?string $installedVersion = null,
        public bool $inCatalog = true,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromCatalog(array $item, ?MarketplaceInstall $install = null): self
    {
        $icon = (array) ($item['icon'] ?? []);

        return new self(
            slug: (string) $item['slug'],
            type: PackageType::from((string) $item['type']),
            name: (string) ($item['name'] ?? $item['slug']),
            summary: (string) ($item['summary'] ?? ''),
            description: (string) ($item['description'] ?? ''),
            category: (string) ($item['category'] ?? ''),
            developer: (string) ($item['developer']['name'] ?? ''),
            verified: (bool) ($item['developer']['verified'] ?? false),
            price: max(0, (int) ($item['price'] ?? 0)),
            updatePrice: max(0, (int) ($item['update_price'] ?? 0)),
            currency: preg_match('/^[A-Z]{3}$/', (string) ($item['currency'] ?? '')) ? (string) $item['currency'] : 'USD',
            version: isset($item['version']) ? (string) $item['version'] : null,
            compatible: (bool) ($item['compatible'] ?? true),
            requires: (string) ($item['requires'] ?? ''),
            permissions: array_values(array_filter((array) ($item['permissions'] ?? []), 'is_string')),
            screenshots: array_values(array_filter((array) ($item['screenshots'] ?? []), fn ($url): bool => is_string($url) && str_starts_with($url, 'https://'))),
            icon: [
                'glyph' => is_string($icon['glyph'] ?? null) && preg_match('/^[MmLlHhVvCcSsQqTtAaZz0-9 .,\-]+$/', $icon['glyph']) ? $icon['glyph'] : '',
                'bg' => self::color($icon['bg'] ?? null, '#EEF2F6'),
                'fg' => self::color($icon['fg'] ?? null, '#0F1B2D'),
            ],
            storeUrl: is_string($item['url'] ?? null) && str_starts_with($item['url'], 'https://') ? $item['url'] : null,
            demoUrl: is_string($item['demo_url'] ?? null) && str_starts_with($item['demo_url'], 'https://') ? $item['demo_url'] : null,
            featured: (bool) ($item['featured'] ?? false),
            install: $install,
            installedVersion: $install?->version,
        );
    }

    /**
     * Something installed here that the catalog does not list: built in, or from elsewhere.
     *
     * @param  list<string>  $permissions
     */
    public static function local(string $slug, PackageType $type, string $name, string $version, string $description, string $author, bool $builtIn, ?MarketplaceInstall $install = null, array $permissions = []): self
    {
        return new self(
            slug: $slug,
            type: $type,
            name: $name,
            summary: $description,
            description: $description,
            developer: $author,
            verified: $builtIn,
            version: $version,
            permissions: $permissions,
            install: $install,
            builtIn: $builtIn,
            installedVersion: $version,
            inCatalog: false,
        );
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    public function isInstalled(): bool
    {
        return $this->install !== null || $this->builtIn;
    }

    public function hasUpdate(): bool
    {
        return $this->install !== null && $this->version !== null && version_compare($this->version, (string) $this->installedVersion, '>');
    }

    public function priceLabel(): string
    {
        return $this->isFree() ? __('Free') : money($this->price, $this->currency);
    }

    private static function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? $value : $fallback;
    }
}
