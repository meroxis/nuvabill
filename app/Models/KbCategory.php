<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Database\Factories\KbCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of knowledge base articles, for example "Email" or "Billing".
 */
class KbCategory extends Model
{
    /** @use HasFactory<KbCategoryFactory> */
    use HasFactory;

    use Translatable;

    public const TRANSLATED_FIELDS = ['title' => 'name', 'body' => 'description'];

    protected $fillable = ['name', 'slug', 'description', 'sort_order', 'is_visible'];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<KbArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
