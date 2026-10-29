<?php

namespace Database\Factories;

use App\Models\Token;
use App\Models\TokenSecurityCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TokenSecurityCheck>
 */
class TokenSecurityCheckFactory extends Factory
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
            'provider' => 'goplus',
            'holder_count' => 10_000,
            'top10_percent' => 20,
            'lp_locked_percent' => 90,
            'is_honeypot' => false,
            'buy_tax' => 0,
            'sell_tax' => 0,
            'liquidity_usd' => 1_000_000,
            'risk_score' => 90,
            'flags' => [],
            'checked_at' => now(),
        ];
    }
}
