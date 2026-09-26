<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The price list for one domain extension (for example "com") in one currency.
 * Prices are per year; longer periods cost the yearly price times the years.
 */
class TldPrice extends Model
{
    protected $fillable = [
        'tld',
        'currency',
        'registrar',
        'register_price',
        'transfer_price',
        'renew_price',
        'min_years',
        'max_years',
        'epp_required',
        'is_featured',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'register_price' => 'integer',
            'transfer_price' => 'integer',
            'renew_price' => 'integer',
            'min_years' => 'integer',
            'max_years' => 'integer',
            'epp_required' => 'boolean',
            'is_featured' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<TldPrice>  $query
     */
    public function scopeEnabled(Builder $query, string $currency): void
    {
        $query->where('is_enabled', true)->where('currency', $currency);
    }

    public static function forTld(string $tld, string $currency): ?self
    {
        return self::query()->enabled($currency)->where('tld', strtolower(ltrim($tld, '.')))->first();
    }

    /**
     * The price for the given action ("register", "transfer" or "renew") and number of years.
     */
    public function priceFor(string $action, int $years): int
    {
        $yearly = match ($action) {
            'transfer' => $this->transfer_price,
            'renew' => $this->renew_price,
            default => $this->register_price,
        };

        return $yearly * max(1, $years);
    }

    /**
     * @return list<int>
     */
    public function yearOptions(): array
    {
        return range(max(1, $this->min_years), max($this->min_years, min(10, $this->max_years)));
    }
}
