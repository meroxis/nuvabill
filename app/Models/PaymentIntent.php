<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment a gateway started for an invoice, for example a FIB payment or a Wayl payment link.
 * Lets gateways find the invoice again from their own reference when a notification arrives.
 */
class PaymentIntent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['invoice_id', 'gateway', 'reference', 'amount', 'currency', 'status', 'meta', 'expires_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'meta' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public static function findFor(string $gateway, string $reference): ?self
    {
        return self::query()->where('gateway', $gateway)->where('reference', $reference)->first();
    }

    /**
     * The newest unexpired intent for an invoice, so a client who reloads the page sees the same payment.
     */
    public static function latestOpenFor(Invoice $invoice, string $gateway): ?self
    {
        return self::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', $gateway)
            ->where('status', self::STATUS_PENDING)
            ->where('amount', $invoice->balance())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addMinute()))
            ->latest('id')
            ->first();
    }
}
