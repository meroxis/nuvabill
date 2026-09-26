<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => (string) fake()->unique()->numberBetween(100000, 999999),
            'client_id' => Client::factory(),
            'ticket_department_id' => TicketDepartment::factory(),
            'subject' => fake()->sentence(5),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
            'last_reply_at' => now(),
        ];
    }
}
