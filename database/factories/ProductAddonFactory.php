<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\ProductAddon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAddon>
 */
class ProductAddonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
            'description' => 'Keeps 30 days of copies.',
            'is_visible' => true,
        ];
    }

    /**
     * Add a price. Amounts are in cents.
     */
    public function priced(int $price = 200, BillingCycle $cycle = BillingCycle::Monthly, string $currency = 'USD'): static
    {
        return $this->afterCreating(fn (ProductAddon $addon) => $addon->prices()->create([
            'currency' => $currency,
            'billing_cycle' => $cycle,
            'price' => $price,
        ]));
    }
}
