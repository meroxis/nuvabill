<?php

namespace Database\Factories;

use App\Models\TicketDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketDepartment>
 */
class TicketDepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Technical support', 'Billing', 'Sales']),
            'email' => null,
            'is_visible' => true,
            'sort_order' => 0,
        ];
    }
}
