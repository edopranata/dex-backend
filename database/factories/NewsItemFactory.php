<?php

namespace Database\Factories;

use App\Models\NewsItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsItem>
 */
class NewsItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'CoinDesk',
            'title' => fake()->sentence(),
            'url' => $url = fake()->unique()->url(),
            'url_hash' => hash('sha256', $url),
            'summary' => fake()->paragraph(),
            'published_at' => now()->subHours(fake()->numberBetween(1, 24)),
        ];
    }
}
