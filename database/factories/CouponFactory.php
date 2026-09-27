<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(8)),
            'type' => Coupon::TYPE_PERCENT,
            'value' => 20,
            'recurring' => Coupon::RECURRING_FIRST,
            'is_active' => true,
        ];
    }

    /**
     * A fixed amount off, in cents.
     */
    public function fixed(int $amount, string $currency = 'USD'): static
    {
        return $this->state(['type' => Coupon::TYPE_FIXED, 'value' => $amount, 'currency' => $currency]);
    }
}
