<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'INV-'.fake()->unique()->numberBetween(1000, 999999),
            'client_id' => Client::factory(),
            'status' => InvoiceStatus::Unpaid,
            'currency' => 'USD',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
            'amount_paid' => 0,
            'issued_at' => today(),
            'due_at' => today()->addDays(7),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
            'amount_paid' => $attributes['total'],
            'paid_at' => now(),
        ]);
    }

    public function overdue(int $days = 5): static
    {
        return $this->state(['due_at' => today()->subDays($days), 'issued_at' => today()->subDays($days + 7)]);
    }
}
