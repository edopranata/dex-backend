<?php

namespace Database\Factories;

use App\Models\Candle;
use App\Models\Token;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candle>
 */
class CandleFactory extends Factory
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
            'open_time' => now()->startOfHour()->timestamp,
            'open' => 1.0,
            'high' => 1.02,
            'low' => 0.98,
            'close' => 1.01,
            'volume' => 10_000,
        ];
    }
}
