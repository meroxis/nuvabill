<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'permissions' => [],
        ];
    }

    public function owner(): static
    {
        return $this->state(['name' => 'Owner', 'permissions' => ['*']]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function with(array $permissions): static
    {
        return $this->state(['permissions' => $permissions]);
    }
}
