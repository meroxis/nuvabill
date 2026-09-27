<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code clients enter in the cart or open with a link (?coupon=CODE).
 *
 * Percent coupons take a share off; fixed coupons take an amount off each item, never more than
 * the item costs. "recurring" says which payments get the discount: only the first, every one,
 * or the first {recurring_count}.
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    public const RECURRING_FIRST = 'first';

    public const RECURRING_EVERY = 'every';

    public const RECURRING_COUNT = 'count';

    protected $fillable = [
        'code', 'type', 'value', 'currency', 'product_ids', 'billing_cycles', 'applies_to_domains',
        'recurring', 'recurring_count', 'starts_at', 'ends_at', 'max_uses', 'max_uses_per_client',
        'new_clients_only', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'product_ids' => 'array',
            'billing_cycles' => 'array',
            'applies_to_domains' => 'boolean',
            'recurring_count' => 'integer',
            'starts_at' => 'immutable_date',
            'ends_at' => 'immutable_date',
            'max_uses' => 'integer',
            'max_uses_per_client' => 'integer',
            'new_clients_only' => 'boolean',
            'is_active' => 'boolean',
            'uses' => 'integer',
        ];
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function findByCode(string $code): ?self
    {
        $code = self::normalize($code);

        return $code === '' ? null : self::query()->where('code', $code)->first();
    }

    /**
     * Why this coupon cannot be used right now, in words for the client, or null when it can.
     */
    public function unavailableReason(?Client $client, string $currency, ?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::today();

        if (! $this->is_active || ($this->ends_at !== null && $today->gt($this->ends_at))) {
            return __('This coupon has ended.');
        }

        if ($this->starts_at !== null && $today->lt($this->starts_at)) {
            return __('This coupon starts on :date.', ['date' => $this->starts_at->translatedFormat('d M Y')]);
        }

        if ($this->max_uses !== null && $this->uses >= $this->max_uses) {
            return __('This coupon has been used up.');
        }

        if ($this->type === self::TYPE_FIXED && $this->currency !== null && strtoupper($currency) !== $this->currency) {
            return __('This coupon only works for prices in :currency.', ['currency' => $this->currency]);
        }

        if ($client !== null) {
            if ($this->new_clients_only && $client->orders()->exists()) {
                return __('This coupon is for new clients only.');
            }

            if ($this->max_uses_per_client !== null && $this->redemptions()->where('client_id', $client->id)->count() >= $this->max_uses_per_client) {
                return __('You have already used this coupon.');
            }
        }

        return null;
    }

    public function appliesToProduct(Product $product, ?BillingCycle $cycle): bool
    {
        if (! empty($this->product_ids) && ! in_array($product->id, array_map('intval', $this->product_ids), true)) {
            return false;
        }

        return empty($this->billing_cycles) || ($cycle !== null && in_array($cycle->value, $this->billing_cycles, true));
    }

    /**
     * The discount on an amount, never more than the amount itself.
     */
    public function discountOn(int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $discount = $this->type === self::TYPE_PERCENT
            ? (int) round($amount * min(100, max(0, $this->value)) / 100)
            : $this->value;

        return min($amount, max(0, $discount));
    }

    /**
     * Whether renewals keep getting the discount after the first payment.
     */
    public function isRecurring(): bool
    {
        return $this->recurring === self::RECURRING_EVERY
            || ($this->recurring === self::RECURRING_COUNT && (int) $this->recurring_count > 1);
    }

    /**
     * "20% off" or "$10.00 off".
     */
    public function describe(): string
    {
        return $this->type === self::TYPE_PERCENT
            ? __(':value% off', ['value' => $this->value])
            : __(':amount off', ['amount' => money($this->value, $this->currency)]);
    }

    /**
     * "First payment", "Every payment" or "First 3 payments".
     */
    public function paymentsLabel(): string
    {
        return match ($this->recurring) {
            self::RECURRING_EVERY => __('Every payment'),
            self::RECURRING_COUNT => trans_choice('First payment|First :count payments', (int) $this->recurring_count, ['count' => (int) $this->recurring_count]),
            default => __('First payment'),
        };
    }

    public function isExpired(): bool
    {
        return ! $this->is_active
            || ($this->ends_at !== null && CarbonImmutable::today()->gt($this->ends_at))
            || ($this->max_uses !== null && $this->uses >= $this->max_uses);
    }

    public function isScheduled(): bool
    {
        return $this->starts_at !== null && CarbonImmutable::today()->lt($this->starts_at);
    }
}
