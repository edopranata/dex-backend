<?php

namespace Database\Factories;

use App\Enums\Recommendation;
use App\Models\Analysis;
use App\Models\Token;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Analysis>
 */
class AnalysisFactory extends Factory
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
            'timeframe' => '1h',
            'recommendation' => Recommendation::Buy,
            'final_score' => 65,
            'technical_score' => 70,
            'onchain_score' => 80,
            'whale_score' => 60,
            'sentiment_score' => 55,
            'fundamental_score' => 70,
            'price' => 1.0,
            'entry_low' => 0.98,
            'entry_high' => 1.0,
            'stop_loss' => 0.95,
            'take_profit_1' => 1.065,
            'take_profit_2' => 1.115,
            'take_profit_3' => 1.19,
            'indicators' => [],
            'reasons' => [],
            'summary' => fake()->sentence(),
            'summary_provider' => 'template',
        ];
    }
}
