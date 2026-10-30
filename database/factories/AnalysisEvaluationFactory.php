<?php

namespace Database\Factories;

use App\Enums\Recommendation;
use App\Models\AnalysisEvaluation;
use App\Models\Token;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisEvaluation>
 */
class AnalysisEvaluationFactory extends Factory
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
            'recommendation' => Recommendation::Buy,
            'final_score' => 65,
            'scores' => ['technical' => 70, 'onchain' => 60, 'whale' => 60, 'sentiment' => 50, 'fundamental' => 60],
            'price' => 1.0,
            'analyzed_at' => now()->subDay(),
        ];
    }
}
