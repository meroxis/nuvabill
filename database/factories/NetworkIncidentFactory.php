<?php

namespace Database\Factories;

use App\Enums\IncidentStatus;
use App\Models\NetworkIncident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetworkIncident>
 */
class NetworkIncidentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Email is slow',
            'kind' => NetworkIncident::KIND_ISSUE,
            'status' => IncidentStatus::Investigating,
            'impact' => NetworkIncident::IMPACT_MINOR,
            'server_ids' => null,
            'starts_at' => now()->subHour(),
        ];
    }

    public function maintenance(): static
    {
        return $this->state([
            'title' => 'Faster disks for server 2',
            'kind' => NetworkIncident::KIND_MAINTENANCE,
            'status' => IncidentStatus::Scheduled,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(2),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(['status' => IncidentStatus::Resolved, 'resolved_at' => now()->subHour()]);
    }
}
