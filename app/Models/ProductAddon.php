<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Database\Factories\ProductAddonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extra clients can add to a service when they order it, for example daily backups.
 * It is billed with the service on the same cycle. Empty product_ids means every product.
 */
class ProductAddon extends Model
{
    /** @use HasFactory<ProductAddonFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'icon', 'product_ids', 'is_visible', 'is_popular', 'sort_order'];

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'is_visible' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<ProductAddonPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductAddonPrice::class);
    }

    /**
     * @param  Builder<ProductAddon>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    public function appliesTo(Product $product): bool
    {
        return empty($this->product_ids) || in_array($product->id, array_map('intval', $this->product_ids), true);
    }

    public function priceFor(string $currency, BillingCycle $cycle): ?ProductAddonPrice
    {
        return $this->prices->first(
            fn (ProductAddonPrice $price): bool => $price->currency === $currency && $price->billing_cycle === $cycle,
        );
    }

    /**
     * Visible add-ons for a product that have a price for the cycle, in display order.
     *
     * @return Collection<int, ProductAddon>
     */
    public static function offeredFor(Product $product, string $currency, ?BillingCycle $cycle = null): Collection
    {
        return self::query()->visible()->with('prices')->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (ProductAddon $addon): bool => $addon->appliesTo($product)
                && ($cycle === null ? $addon->prices->where('currency', $currency)->isNotEmpty() : $addon->priceFor($currency, $cycle) !== null))
            ->values();
    }
}
