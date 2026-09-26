<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'client_id' => Client::factory(),
            'status' => OrderStatus::Pending,
            'currency' => 'USD',
            'total' => 1000,
        ];
    }
}
