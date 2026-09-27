<?php

namespace App\Models;

use App\Marketplace\PackageType;
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

    protected $fillable = [
        'developer_id', 'slug', 'type', 'name', 'summary', 'description', 'category', 'price', 'update_price', 'currency',
        'status', 'is_featured', 'icon', 'screenshots', 'demo_url', 'docs_url', 'permissions', 'product_id', 'latest_version_id',
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
}
