<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\Resources\ResourceExtras;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'order_fields',
    ];

    /**
     * order_fields: what the client entered in a server module's order form fields, encrypted.
     * Some may be secret, so it never goes into JSON (see App\Provisioning\OrderFields).
     */
    protected $hidden = ['password', 'order_fields'];

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'billing_cycle' => BillingCycle::class,
            'password' => 'encrypted',
            'module_data' => 'array',
            'order_fields' => 'encrypted:array',
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
     * The coupon that takes money off the next renewal, or null: it must still have payments left
     * and be made for the plan and billing cycle the renewal bills. A coupon made for other plans
     * gives nothing while the service is on one of them; it stays on the service and counts again
     * once the service is back on a plan it covers.
     *
     * @param  Product|null  $product  The plan the renewal bills, when it is not the service's own (a planned move).
     */
    public function renewalCoupon(?Product $product = null): ?Coupon
    {
        $coupon = $this->coupon;
        $product ??= $this->product;

        if ($coupon === null || $product === null || ($this->coupon_payments_left !== null && $this->coupon_payments_left <= 0)) {
            return null;
        }

        return $coupon->appliesToProduct($product, $this->billing_cycle) ? $coupon : null;
    }

    /**
     * @return HasMany<ServiceAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(ServiceAddon::class);
    }

    /**
     * The extras the service has on top of its plan, one row per kind (cancelled rows stay for
     * their invoice lines).
     *
     * @return HasMany<ServiceResourceOption, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(ServiceResourceOption::class);
    }

    /**
     * The dedicated server machine the service holds, if its product is sold from the hardware
     * inventory.
     *
     * @return HasOne<Machine, $this>
     */
    public function machine(): HasOne
    {
        return $this->hasOne(Machine::class);
    }

    /**
     * What the client pays each renewal: the plan price (recurring_amount) plus the active add-ons
     * and the active extras, in minor units of the service's currency. Loaded relations are used
     * when present.
     */
    public function renewalTotal(): int
    {
        $addons = $this->relationLoaded('addons')
            ? $this->addons->filter(fn (ServiceAddon $addon): bool => $addon->isActive())->sum('recurring_amount')
            : $this->addons()->where('status', ServiceAddon::STATUS_ACTIVE)->sum('recurring_amount');

        $resources = $this->relationLoaded('resources')
            ? $this->resources->filter(fn (ServiceResourceOption $row): bool => $row->isActive())->sum('recurring_amount')
            : $this->resources()->where('status', ServiceResourceOption::STATUS_ACTIVE)->where('steps', '>', 0)->sum('recurring_amount');

        return (int) $this->recurring_amount + (int) $addons + (int) $resources;
    }

    /**
     * The extras the server must have on top of the plan, in units, for server modules: every kind
     * the service has a row for (its active steps × step size, or 0 when cancelled), and every kind
     * its product has an option for (0 without a row). A kind at 0 goes back to the plan.
     */
    public function resourceExtras(): ResourceExtras
    {
        $units = [];

        foreach (ResourceOption::query()->where('product_id', $this->product_id)->orderBy('sort_order')->orderBy('id')->pluck('kind') as $kind) {
            $units[(string) $kind] = 0;
        }

        foreach ($this->resources()->orderBy('id')->get() as $row) {
            $units[$row->kind] = $row->units();
        }

        return new ResourceExtras($units);
    }

    /**
     * After a plan change: point each of the service's rows at the new product's option of the same
     * kind and step size, or at none. The steps and the price the client pays are kept. Call it inside
     * the plan change's transaction.
     */
    public function relinkResources(Product $to): void
    {
        $options = ResourceOption::query()->where('product_id', $to->getKey())->get(['id', 'kind', 'step_size']);

        foreach ($this->resources()->orderBy('id')->get() as $row) {
            $match = $options->first(fn (ResourceOption $option): bool => $option->kind === $row->kind && $option->step_size === $row->step_size);

            if ($row->resource_option_id !== $match?->id) {
                $row->update(['resource_option_id' => $match?->id]);
            }
        }

        $this->unsetRelation('resources');
    }

    public function label(): string
    {
        return $this->domain ? $this->product->name.' · '.$this->domain : $this->product->name;
    }
}
