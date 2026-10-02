<?php

namespace App\Models;

use App\Billing\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received from (or refunded to) a client.
 */
class Transaction extends Model
{
    protected $fillable = ['client_id', 'invoice_id', 'gateway', 'reference', 'type', 'amount', 'fee', 'currency', 'meta', 'paid_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'meta' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Money that came in or went back out through a payment method: payments and refunds, without
     * the wallet's own moves. Funds added to a wallet count when they arrive, not again when spent.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRevenue(Builder $query): void
    {
        $query->whereIn('type', ['payment', 'refund'])->where('gateway', '!=', Wallet::GATEWAY);
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

    /**
     * The payment method for lists: "Wallet" for wallet payments, otherwise the gateway name.
     */
    public function gatewayLabel(): string
    {
        return $this->gateway === Wallet::GATEWAY ? __('Wallet') : $this->gateway;
    }
}
