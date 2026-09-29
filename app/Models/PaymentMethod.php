<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A card or PayPal account a client saved to pay renewals automatically. The card number never
 * reaches Nuvabill: only the gateway's reference, the brand, the last 4 digits and the expiry.
 */
class PaymentMethod extends Model
{
    public const TYPE_CARD = 'card';

    public const TYPE_PAYPAL = 'paypal';

    protected $fillable = [
        'client_id', 'gateway', 'type', 'reference', 'customer_reference', 'brand', 'last4',
        'expires_month', 'expires_year', 'email', 'is_default', 'last_used_at', 'expiry_notice_at',
    ];

    protected $hidden = ['reference', 'customer_reference'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'expires_month' => 'integer',
            'expires_year' => 'integer',
            'last_used_at' => 'datetime',
            'expiry_notice_at' => 'datetime',
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
     * "Visa •••• 4242" or "PayPal (name@example.com)".
     */
    public function label(): string
    {
        if ($this->type === self::TYPE_PAYPAL) {
            return $this->email ? 'PayPal ('.$this->email.')' : 'PayPal';
        }

        $brand = match (strtolower((string) $this->brand)) {
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex', 'american_express' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay',
            default => __('Card'),
        };

        return trim($brand.' •••• '.$this->last4);
    }

    /**
     * "08/2028", for cards.
     */
    public function expiry(): ?string
    {
        return $this->expires_month && $this->expires_year ? sprintf('%02d/%d', $this->expires_month, $this->expires_year) : null;
    }

    /**
     * The last day the card works: the end of its expiry month.
     */
    public function expiresOn(): ?Carbon
    {
        return $this->expires_month && $this->expires_year
            ? Carbon::create($this->expires_year, $this->expires_month, 1)->endOfMonth()
            : null;
    }

    public function isExpired(): bool
    {
        return $this->expiresOn()?->isPast() ?? false;
    }
}
