<?php

namespace Database\Factories;

use App\Models\KbArticle;
use App\Models\KbCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KbArticle>
 */
class KbArticleFactory extends Factory
{
    public function definition(): array
    {
        $title = 'How do I '.fake()->unique()->words(3, true).'?';

        return [
            'kb_category_id' => KbCategory::factory(),
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'body' => "Open your client area.\n\n## Steps\n\n- Go to **Services**\n- Pick the service",
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(['is_published' => false]);
    }
}
