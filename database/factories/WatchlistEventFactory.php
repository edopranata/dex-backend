<?php

namespace Database\Factories;

use App\Enums\WatchlistAction;
use App\Models\Token;
use App\Models\WatchlistEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchlistEvent>
 */
class WatchlistEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_id' => Token::factory(),
            'action' => WatchlistAction::Added,
            'reason' => fake()->sentence(),
            'score' => 70,
        ];
    }
}
