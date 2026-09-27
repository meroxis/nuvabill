<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product a client owns, for example one hosting account.
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id',
        'order_id',
        'product_id',
        'server_id',
        'domain',
        'username',
        'password',
        'status',
        'billing_cycle',
        'currency',
        'first_payment_amount',
        'recurring_amount',
        'registration_date',
        'next_due_date',
        'suspended_at',
        'suspension_reason',
        'terminated_at',
        'cancelled_at',
        'module_data',
        'coupon_id',
        'coupon_payments_left',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'billing_cycle' => BillingCycle::class,
            'password' => 'encrypted',
            'module_data' => 'array',
            'first_payment_amount' => 'integer',
            'recurring_amount' => 'integer',
            'registration_date' => 'immutable_date',
            'next_due_date' => 'immutable_date',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'coupon_payments_left' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * The coupon that still takes money off this service's renewals, if any.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<ServiceAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(ServiceAddon::class);
    }

    public function label(): string
    {
        return $this->domain ? $this->product->name.' · '.$this->domain : $this->product->name;
    }
}
