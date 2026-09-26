<?php

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        $expires = today()->addMonths(8);

        return [
            'client_id' => Client::factory(),
            'name' => fake()->unique()->domainWord().'.com',
            'tld' => 'com',
            'registrar' => null,
            'order_type' => Domain::TYPE_REGISTER,
            'status' => DomainStatus::Active,
            'years' => 1,
            'currency' => 'USD',
            'first_payment_amount' => 1299,
            'recurring_amount' => 1499,
            'registered_at' => today()->subMonths(4),
            'expires_at' => $expires,
            'next_due_date' => $expires,
            'auto_renew' => true,
            'nameservers' => ['ns1.example.test', 'ns2.example.test'],
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => DomainStatus::Pending,
            'registered_at' => null,
            'expires_at' => null,
            'next_due_date' => null,
        ]);
    }

    public function withRegistrar(string $slug): static
    {
        return $this->state(['registrar' => $slug]);
    }

    public function expiringOn(\DateTimeInterface $date): static
    {
        return $this->state(['expires_at' => $date, 'next_due_date' => $date]);
    }
}
