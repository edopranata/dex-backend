<?php

namespace Database\Factories;

use App\Models\Token;
use App\Models\TokenSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TokenSnapshot>
 */
class TokenSnapshotFactory extends Factory
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
            'price_usd' => 1.0,
            'liquidity_usd' => 1_000_000,
            'volume_h24' => 500_000,
            'fdv' => 10_000_000,
            'captured_at' => now(),
        ];
    }
}
