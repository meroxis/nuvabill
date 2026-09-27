<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a client's wallet: positive when money is added, negative when it is spent.
 * "balance" is the wallet balance right after the change.
 */
class CreditTransaction extends Model
{
    protected $fillable = ['client_id', 'amount', 'balance', 'currency', 'description', 'invoice_id', 'admin_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance' => 'integer',
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
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
