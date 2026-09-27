<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money sent to a developer for their earnings, recorded by staff after paying.
 */
class Payout extends Model
{
    public const STATUS_PAID = 'paid';

    protected $fillable = ['developer_id', 'amount', 'currency', 'status', 'reference', 'notes', 'paid_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
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
     * @return HasMany<DeveloperEarning, $this>
     */
    public function earnings(): HasMany
    {
        return $this->hasMany(DeveloperEarning::class);
    }
}
