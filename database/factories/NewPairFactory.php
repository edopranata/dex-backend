<?php

namespace Database\Factories;

use App\Models\NewPair;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewPair>
 */
class NewPairFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chain_id' => 'solana',
            'pair_address' => fake()->unique()->regexify('[A-HJ-NP-Za-km-z1-9]{44}'),
            'token_address' => fake()->unique()->regexify('[A-HJ-NP-Za-km-z1-9]{44}'),
            'symbol' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->word(),
            'source' => 'discovery',
            'pool_created_at' => now()->subHour(),
            'first_seen_at' => now(),
            'first_liquidity_usd' => 50_000,
            'liquidity_usd' => 50_000,
            'status' => NewPair::TRACKING,
        ];
    }

    /**
     * GoPlus answered with a clean token.
     */
    public function secure(): static
    {
        return $this->state(fn (): array => ['security_checked_at' => now(), 'security_attempts' => 1, 'security_score' => 95, 'is_honeypot' => false, 'sell_tax' => 0]);
    }
}
