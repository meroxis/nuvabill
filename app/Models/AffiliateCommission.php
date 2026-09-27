<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money an affiliate earned from one paid invoice of a client they referred.
 *
 * Pending during the hold period (so refunds can cancel it), then available, then paid: moved to
 * the affiliate's wallet or paid out by staff.
 */
class AffiliateCommission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['affiliate_id', 'client_id', 'invoice_id', 'amount', 'currency', 'status', 'available_at', 'paid_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'available_at' => 'immutable_date',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => __('On hold until :date', ['date' => $this->available_at->translatedFormat('d M Y')]),
            self::STATUS_AVAILABLE => __('Available'),
            self::STATUS_PAID => __('Paid'),
            default => __('Cancelled'),
        };
    }

    public function tone(): string
    {
        return match ($this->status) {
            self::STATUS_AVAILABLE => 'good',
            self::STATUS_PENDING => 'warn',
            self::STATUS_PAID => 'info',
            default => 'muted',
        };
    }
}
