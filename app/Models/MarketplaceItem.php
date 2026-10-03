<?php

namespace App\Models;

use App\Marketplace\PackageType;
use App\Support\Themes;
use App\Support\WhiteLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A theme, order form or extension listed on the marketplace store. Paid items are sold through
 * a hidden store product: the first payment is the price and each yearly renewal buys updates.
 */
class MarketplaceItem extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_LIVE = 'live';

    public const STATUS_HIDDEN = 'hidden';

    /**
     * Categories shown as filters.
     *
     * @var list<string>
     */
    public const CATEGORIES = ['Client themes', 'Order forms', 'Payments', 'Server modules', 'Domains', 'Notifications', 'Reports', 'Languages', 'Tools'];

    /**
     * What buyers read about an item. Once an item was approved, changes to these wait in
     * pending_listing until staff approve them.
     *
     * @var list<string>
     */
    public const REVIEWED_FIELDS = ['name', 'summary', 'description', 'demo_url', 'docs_url', 'screenshots'];

    protected $fillable = [
        'developer_id', 'slug', 'type', 'name', 'summary', 'description', 'category', 'price', 'update_price', 'currency',
        'status', 'is_featured', 'icon', 'screenshots', 'demo_url', 'docs_url', 'permissions', 'product_id', 'latest_version_id',
        'pending_listing',
    ];

    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'price' => 'integer',
            'update_price' => 'integer',
            'is_featured' => 'boolean',
            'icon' => 'array',
            'screenshots' => 'array',
            'permissions' => 'array',
            'pending_listing' => 'array',
            'installs_count' => 'integer',
            'sales_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Developer, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Developer::class);
    }

    /**
     * @return HasMany<MarketplaceVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(MarketplaceVersion::class);
    }

    /**
     * @return BelongsTo<MarketplaceVersion, $this>
     */
    public function latestVersion(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVersion::class, 'latest_version_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<License, $this>
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * @param  Builder<MarketplaceItem>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', self::STATUS_LIVE)->whereNotNull('latest_version_id');
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE && $this->latest_version_id !== null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Find an item by its slug, or by the slug it had before it was renamed, so sites that
     * installed it under the old name keep their license and old links keep working. An old
     * slug always belongs to the renamed item, even when another item took it later.
     */
    public static function findBySlug(string $slug): ?self
    {
        $renamedTo = self::renamedTo($slug);

        if ($renamedTo !== null) {
            $item = self::query()->where('slug', $renamedTo)->first();

            if ($item !== null) {
                return $item;
            }
        }

        return self::query()->where('slug', $slug)->first();
    }

    /**
     * The current slug of the item that used this slug before it was renamed, if any.
     */
    public static function renamedTo(string $slug): ?string
    {
        $renamed = (array) setting('marketplace.renamed_items', []);

        return isset($renamed[$slug]) && is_string($renamed[$slug]) ? $renamed[$slug] : null;
    }

    /**
     * Slugs developers cannot take for a new item: the themes, order forms and extensions that
     * come with Nuvabill, the White-label license, and slugs staff keep for official items.
     */
    public static function isReservedSlug(string $slug): bool
    {
        $folders = [
            ...(glob(config('nuvabill.extensions_path').'/*/*', GLOB_ONLYDIR) ?: []),
            ...(glob(config('nuvabill.themes_path').'/*', GLOB_ONLYDIR) ?: []),
            ...(glob(config('nuvabill.orderforms_path').'/*', GLOB_ONLYDIR) ?: []),
        ];

        $reserved = [
            ...array_map('basename', $folders),
            Themes::DEFAULT,
            Themes::STANDARD_ORDER_FORM,
            WhiteLabel::SLUG,
            ...array_map('strval', (array) setting('marketplace.reserved_slugs', [])),
        ];

        return in_array(strtolower($slug), array_map('strtolower', $reserved), true);
    }

    /**
     * A copy with the listing changes that wait for review filled in, for forms and previews.
     */
    public function withPendingListing(): self
    {
        $copy = clone $this;

        return $copy->forceFill(array_intersect_key((array) $this->pending_listing, array_flip(self::REVIEWED_FIELDS)));
    }

    /**
     * Pages under the old slug of a renamed item find the item too. Staff and developer pages
     * open the item that has the slug now, so an item that took an old slug can still be managed.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field !== null && $field !== 'slug') {
            return parent::resolveRouteBinding($value, $field);
        }

        return self::query()->where('slug', (string) $value)->first() ?? self::findBySlug((string) $value);
    }
}
