<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone who sells themes or extensions on the marketplace store. Usually a client account;
 * the official Nuvabill developer has no client. Developers keep share_percent of each sale
 * (the store's default when empty); the store keeps the rest.
 */
class Developer extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['client_id', 'name', 'slug', 'website', 'bio', 'is_verified', 'is_official', 'share_percent', 'payout_method', 'payout_details', 'status'];

    protected $hidden = ['payout_details'];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'is_official' => 'boolean',
            'share_percent' => 'integer',
            'payout_details' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<MarketplaceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceItem::class);
    }

    /**
     * @return HasMany<DeveloperEarning, $this>
     */
    public function earnings(): HasMany
    {
        return $this->hasMany(DeveloperEarning::class);
    }

    /**
     * @return HasMany<Payout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * The developer's share of each sale, in percent.
     */
    public function share(): int
    {
        return $this->share_percent ?? (int) setting('marketplace.developer_share', 83);
    }

    /**
     * Earnings not paid out yet, in the given currency.
     */
    public function balance(string $currency): int
    {
        return (int) $this->earnings()->whereNull('payout_id')->where('currency', $currency)->sum('developer_share');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
