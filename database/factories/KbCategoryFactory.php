<?php

namespace Database\Factories;

use App\Models\KbCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KbCategory>
 */
class KbCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Getting started', 'Email', 'Billing', 'Domains', 'Websites', 'Security', 'Servers', 'Backups']);

        return [
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'description' => 'Answers about '.strtolower($name).'.',
            'sort_order' => 0,
            'is_visible' => true,
        ];
    }

    public function hidden(): static
    {
        return $this->state(['is_visible' => false]);
    }
}
