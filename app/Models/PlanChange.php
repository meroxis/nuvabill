<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service moving to another product: waiting for its invoice to be paid, waiting for the next
 * renewal, done, or cancelled. The difference is what the client pays now (positive) or gets back
 * (negative) for the rest of the billing period.
 */
class PlanChange extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    /** The client pays the difference first; the change happens when the invoice is paid. */
    public const MODE_INVOICE = 'invoice';

    /** The change happens now; any unused amount goes to the client's wallet. */
    public const MODE_NOW = 'now';

    /** The change happens on the next renewal date, with no money back. */
    public const MODE_RENEWAL = 'renewal';

    protected $fillable = [
        'service_id',
        'client_id',
        'invoice_id',
        'admin_id',
        'from_product_id',
        'to_product_id',
        'billing_cycle',
        'currency',
        'old_amount',
        'new_amount',
        'difference',
        'mode',
        'status',
        'apply_on',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'old_amount' => 'integer',
            'new_amount' => 'integer',
            'difference' => 'integer',
            'apply_on' => 'immutable_date',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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
     * @return BelongsTo<Product, $this>
     */
    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
