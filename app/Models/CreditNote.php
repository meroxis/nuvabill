<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit note: a numbered document that takes back all or part of a paid invoice. The invoice
 * itself never changes. The money goes back to the client, into their wallet, or was settled
 * another way.
 */
class CreditNote extends Model
{
    public const METHOD_REFUND = 'refund';

    public const METHOD_WALLET = 'wallet';

    public const METHOD_NONE = 'none';

    public const METHODS = [self::METHOD_REFUND, self::METHOD_WALLET, self::METHOD_NONE];

    protected $fillable = ['number', 'invoice_id', 'client_id', 'admin_id', 'currency', 'subtotal', 'tax', 'total', 'tax_name', 'tax_rate', 'items', 'method', 'reason', 'issued_at'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'tax_rate' => 'integer',
            'items' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function displayNumber(): string
    {
        return $this->number ?? '#'.$this->id;
    }

    public function methodLabel(): string
    {
        return self::methodName($this->method);
    }

    public static function methodName(string $method): string
    {
        return match ($method) {
            self::METHOD_REFUND => __('Money sent back'),
            self::METHOD_WALLET => __('Added to the wallet'),
            default => __('Settled another way'),
        };
    }

    public function taxLabel(): string
    {
        $name = $this->tax_name ?: __('Tax');

        return $this->tax_rate !== null ? $name.' ('.TaxRule::formatRate($this->tax_rate).')' : $name;
    }
}
