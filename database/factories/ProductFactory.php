<?php

namespace Database\Factories;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'product_group_id' => ProductGroup::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'type' => ProductType::Hosting,
            'description' => "10 GB storage\nFree SSL",
            'is_visible' => true,
            'requires_domain' => true,
            'server_module' => null,
            'auto_setup' => AutoSetup::OnPayment,
        ];
    }

    /**
     * Add a price. Amounts are in cents.
     */
    public function priced(int $price = 1000, BillingCycle $cycle = BillingCycle::Monthly, int $setupFee = 0, string $currency = 'USD'): static
    {
        return $this->afterCreating(fn (Product $product) => $product->prices()->create([
            'currency' => $currency,
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => $setupFee,
        ]));
    }

    public function cpanel(?Server $server = null): static
    {
        return $this->state([
            'server_module' => 'cpanel',
            'server_id' => $server?->id,
            'module_config' => ['package' => 'starter'],
        ]);
    }

    public function directadmin(?Server $server = null): static
    {
        return $this->state([
            'server_module' => 'directadmin',
            'server_id' => $server?->id,
            'module_config' => ['package' => 'starter'],
        ]);
    }

    public function withoutDomain(): static
    {
        return $this->state(['requires_domain' => false]);
    }
}
