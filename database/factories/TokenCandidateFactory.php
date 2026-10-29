<?php

namespace Database\Factories;

use App\Enums\CandidateStatus;
use App\Models\TokenCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TokenCandidate>
 */
class TokenCandidateFactory extends Factory
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
            'symbol' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->word(),
            'quote_symbol' => 'SOL',
            'sources' => ['geckoterminal_trending'],
            'price_usd' => 0.01,
            'price_change_h24' => 20,
            'volume_h24' => 500_000,
            'liquidity_usd' => 200_000,
            'trend_score' => 75,
            'attention_score' => 35,
            'whale_score' => 70,
            'security_score' => 90,
            'discovery_score' => 70,
            'whale_checked_at' => now(),
            'security_checked_at' => now(),
            'status' => CandidateStatus::Candidate,
            'reasons' => [],
            'pair_created_at' => now()->subDays(10),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
