<?php

namespace App\Models;

use App\Seo\Sitemap;
use Database\Factories\ProductGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductGroup extends Model
{
    /** @use HasFactory<ProductGroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'sort_order', 'is_visible', 'seo_title', 'seo_description', 'seo_hidden'];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
            'seo_hidden' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Sitemap::forget());
        static::deleted(fn () => Sitemap::forget());

        // A new web address: the group page and its product pages forward from the old addresses.
        static::updated(function (ProductGroup $group): void {
            if (! $group->wasChanged('slug')) {
                return;
            }

            $old = (string) $group->getOriginal('slug');
            SeoRedirect::remember('store/'.$old, 'store/'.$group->slug);

            foreach ($group->products()->pluck('slug') as $slug) {
                SeoRedirect::remember('store/'.$old.'/'.$slug, 'store/'.$group->slug.'/'.$slug);
            }
        });
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @param  Builder<ProductGroup>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
