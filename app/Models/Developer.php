<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Someone who sells themes or extensions on the marketplace store. Usually a client account;
 * the official Nuvabill developer has no client. Developers keep share_percent of each sale
 * (the store's default when empty); the store keeps the rest.
 */
class Developer extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Slugs clients cannot get when they join, so nobody looks like the official developer.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = ['nuvabill', 'official', 'marketplace', 'admin', 'staff', 'support'];

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

    /**
     * The official developer with this name, made when it does not exist yet. A client account
     * that has the same slug is never used for official items.
     *
     * @throws RuntimeException When the slug belongs to a developer that is not official.
     */
    public static function official(string $name): self
    {
        $slug = Str::slug($name);
        $developer = self::query()->where('slug', $slug)->first();

        if ($developer !== null && (! $developer->is_official || $developer->client_id !== null)) {
            throw new RuntimeException("The developer slug {$slug} belongs to an account that is not official.");
        }

        return $developer ?? self::create([
            'client_id' => null,
            'name' => $name,
            'slug' => $slug,
            'is_official' => true,
            'is_verified' => true,
            'status' => self::STATUS_ACTIVE,
        ]);
    }
}
