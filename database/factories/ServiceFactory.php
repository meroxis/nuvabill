<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'product_id' => Product::factory(),
            'domain' => fake()->unique()->domainWord().'.test',
            'status' => ServiceStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'currency' => 'USD',
            'first_payment_amount' => 1000,
            'recurring_amount' => 1000,
            'registration_date' => today()->subMonths(2),
            'next_due_date' => today()->addDays(20),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => ServiceStatus::Pending]);
    }

    public function suspended(string $reason = 'Overdue on payment'): static
    {
        return $this->state(['status' => ServiceStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => $reason]);
    }

    public function dueOn(\DateTimeInterface $date): static
    {
        return $this->state(['next_due_date' => $date]);
    }
}
