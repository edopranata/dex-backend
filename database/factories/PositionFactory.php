<?php

namespace Database\Factories;

use App\Models\Position;
use App\Models\Token;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_id' => Token::factory(),
            'quantity' => 100,
            'avg_price' => 1.0,
            'realized_pnl' => 0,
        ];
    }
}
