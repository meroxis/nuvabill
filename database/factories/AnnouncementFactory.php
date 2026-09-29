<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        $title = 'New '.fake()->unique()->words(2, true).' plans';

        return [
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'body' => 'We added new plans with **more space**.',
            'is_published' => true,
            'published_at' => now()->subDay(),
        ];
    }

    public function scheduled(): static
    {
        return $this->state(['published_at' => now()->addWeek()]);
    }
}
