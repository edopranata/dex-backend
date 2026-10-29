<?php

namespace Database\Factories;

use App\Models\Token;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Token>
 */
class TokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chain_id' => 'solana',
            'pair_address' => fake()->unique()->regexify('[A-HJ-NP-Za-km-z1-9]{44}'),
            'token_address' => fake()->unique()->regexify('[A-HJ-NP-Za-km-z1-9]{44}'),
            'symbol' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
            'quote_symbol' => 'USDC',
            'dex_id' => 'raydium',
            'price_usd' => 1.0,
            'price_change_h24' => fake()->randomFloat(2, -10, 10),
            'volume_h24' => 500_000,
            'liquidity_usd' => 1_000_000,
            'fdv' => 10_000_000,
            'market_cap' => 10_000_000,
            'txns_h24_buys' => 1000,
            'txns_h24_sells' => 900,
            'pair_created_at' => now()->subDays(200),
            'price_updated_at' => now(),
            'is_active' => true,
        ];
    }
}
