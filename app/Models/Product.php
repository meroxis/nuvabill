<?php

namespace App\Models;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Enums\ServiceStatus;
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
        ];
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
        if ($this->stock === null) {
            return true;
        }

        $used = $this->services()->whereNotIn('status', [ServiceStatus::Terminated, ServiceStatus::Cancelled])->count();

        return $used < $this->stock;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
