<?php

namespace Database\Factories;

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Models\Analysis;
use App\Models\Signal;
use App\Models\Token;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
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
            'analysis_id' => fn (array $attributes) => Analysis::factory()->create(['token_id' => $attributes['token_id']])->id,
            'recommendation' => Recommendation::Buy,
            'status' => SignalStatus::Pending,
            'entry_low' => 0.98,
            'entry_high' => 1.0,
            'stop_loss' => 0.95,
            'take_profit_1' => 1.06,
            'take_profit_2' => 1.10,
            'take_profit_3' => 1.16,
        ];
    }

    /**
     * A signal whose entry has been reached at the given price.
     */
    public function active(float $entryPrice = 1.0): static
    {
        return $this->state(fn (): array => [
            'status' => SignalStatus::Active,
            'entry_price' => $entryPrice,
            'entered_at' => now(),
        ]);
    }
}
