<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client who shares a referral link. People who sign up through it become their referrals,
 * and the affiliate earns a commission when those referrals pay.
 */
class Affiliate extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['client_id', 'code', 'status', 'percent', 'clicks'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'clicks' => 0,
    ];

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
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
     * @return HasMany<AffiliateReferral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    /**
     * @return HasMany<AffiliateCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The commission rate in percent: this affiliate's own, or the setting.
     */
    public function rate(): float
    {
        return $this->percent !== null ? (float) $this->percent : (float) setting('affiliates.percent');
    }

    public function link(): string
    {
        return url('/').'?ref='.$this->code;
    }
}
