<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public const TYPE_SERVICE = 'service';

    public const TYPE_SETUP = 'setup';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_ADDON = 'addon';

    /**
     * A coupon discount. The amount is negative.
     */
    public const TYPE_DISCOUNT = 'discount';

    /**
     * Money a client adds to their wallet. Never taxed.
     */
    public const TYPE_CREDIT = 'credit';

    /**
     * The price difference for moving a service to another plan for the rest of its period.
     */
    public const TYPE_PLAN_CHANGE = 'plan_change';

    public const TYPE_DOMAIN_REGISTER = 'domain_register';

    public const TYPE_DOMAIN_TRANSFER = 'domain_transfer';

    public const TYPE_DOMAIN_RENEW = 'domain_renew';

    /**
     * Line types that pay for a domain period.
     *
     * @var list<string>
     */
    public const DOMAIN_TYPES = [self::TYPE_DOMAIN_REGISTER, self::TYPE_DOMAIN_TRANSFER, self::TYPE_DOMAIN_RENEW];

    protected $fillable = ['invoice_id', 'service_id', 'domain_id', 'type', 'description', 'amount', 'taxed', 'period_start', 'period_end', 'billing_key'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'taxed' => 'boolean',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
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
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
