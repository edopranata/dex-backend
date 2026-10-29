<?php

namespace Database\Factories;

use App\Enums\OrderSide;
use App\Models\Token;
use App\Models\WhaleTrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhaleTrade>
 */
class WhaleTradeFactory extends Factory
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
            'external_id' => fake()->unique()->uuid(),
            'tx_hash' => fake()->sha256(),
            'wallet' => fake()->regexify('[A-HJ-NP-Za-km-z1-9]{44}'),
            'side' => fake()->randomElement(OrderSide::cases()),
            'amount_usd' => fake()->numberBetween(10_000, 100_000),
            'token_amount' => fake()->numberBetween(10_000, 100_000),
            'price_usd' => 1.0,
            'traded_at' => now()->subMinutes(fake()->numberBetween(1, 600)),
        ];
    }
}
