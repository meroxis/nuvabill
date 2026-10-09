<?php

namespace App\Models;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Enums\ServiceStatus;
use App\Seo\Sitemap;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'product_group_id',
        'name',
        'slug',
        'type',
        'description',
        'is_visible',
        'taxable',
        'requires_domain',
        'server_module',
        'server_id',
        'module_config',
        'auto_setup',
        'stock',
        'sort_order',
        'seo_title',
        'seo_description',
        'seo_hidden',
        'upgrade_product_ids',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'taxable' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'auto_setup' => AutoSetup::class,
            'is_visible' => 'boolean',
            'taxable' => 'boolean',
            'requires_domain' => 'boolean',
            'module_config' => 'array',
            'stock' => 'integer',
            'sort_order' => 'integer',
            'seo_hidden' => 'boolean',
            'upgrade_product_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Sitemap::forget());
        static::deleted(fn () => Sitemap::forget());

        // A new web address or group: the old store address forwards to the new one.
        static::updated(function (Product $product): void {
            if ($product->wasChanged(['slug', 'product_group_id'])) {
                $oldGroup = ProductGroup::query()->find($product->getOriginal('product_group_id'));
                $product->unsetRelation('group');

                if ($oldGroup !== null && $product->group !== null) {
                    SeoRedirect::remember('store/'.$oldGroup->slug.'/'.$product->getOriginal('slug'), $product->storePath());
                }
            }
        });
    }

    /**
     * @return BelongsTo<ProductGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    public function priceFor(string $currency, BillingCycle $cycle): ?ProductPrice
    {
        return $this->prices->first(
            fn (ProductPrice $price): bool => $price->currency === $currency && $price->billing_cycle === $cycle,
        );
    }

    /**
     * Prices in the given currency, ordered from the shortest cycle to the longest.
     *
     * @return Collection<int, ProductPrice>
     */
    public function pricesIn(string $currency): Collection
    {
        $order = array_flip(array_map(fn (BillingCycle $cycle): string => $cycle->value, BillingCycle::cases()));

        return $this->prices
            ->where('currency', $currency)
            ->sortBy(fn (ProductPrice $price): int => $order[$price->billing_cycle->value])
            ->values();
    }

    public function startingPrice(string $currency): ?ProductPrice
    {
        return $this->pricesIn($currency)->first();
    }

    public function isInStock(): bool
    {
        $left = $this->stockLeft();

        return $left === null || $left > 0;
    }

    /**
     * How many more can be sold, or null when the product has no stock limit. Good for showing;
     * where the answer decides a sale or a plan change, use lockedStockLeft().
     */
    public function stockLeft(): ?int
    {
        if ($this->stock === null) {
            return null;
        }

        return max(0, $this->stock - $this->servicesHoldingStock()->count());
    }

    /**
     * How many more can be sold, or null when the product has no stock limit, counted from the
     * newest saved rows. Use it where the answer decides a sale or a plan change, inside that
     * database transaction: the product's row and then its services stay locked until it ends, so
     * no other checkout can take one in the meantime.
     *
     * On MySQL and MariaDB a plain count can read an older snapshot of the transaction and miss an
     * order another checkout saved a moment ago. This locking read cannot. Call it before anything
     * else is read in the transaction. Lock rows in the one order checkouts and plan changes use, so
     * two of them never wait for each other in a circle: a plan change, then a client, then a coupon,
     * then every product the transaction needs (in id order), then services. Lock any other product
     * first, then call this.
     */
    public function lockedStockLeft(): ?int
    {
        // A product deleted meanwhile has nothing left to sell.
        $saved = static::query()->whereKey($this->getKey())->lockForUpdate()->first(['id', 'stock']);

        if ($saved === null) {
            return 0;
        }

        if ($saved->stock === null) {
            return null;
        }

        // The ids are counted here because PostgreSQL cannot lock the rows of a COUNT. In id order,
        // so every caller locks them in the same order.
        $used = $this->servicesHoldingStock()->orderBy('services.id')->lockForUpdate()->pluck('services.id')->count();

        return max(0, $saved->stock - $used);
    }

    /**
     * The services that hold one of the product's stock: all but terminated and cancelled ones.
     *
     * @return HasMany<Service, $this>
     */
    private function servicesHoldingStock(): HasMany
    {
        return $this->services()->whereNotIn('status', [ServiceStatus::Terminated, ServiceStatus::Cancelled]);
    }

    /**
     * The product's store address without the domain, for example store/web-hosting/starter.
     */
    public function storePath(): string
    {
        return 'store/'.$this->group?->slug.'/'.$this->slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
