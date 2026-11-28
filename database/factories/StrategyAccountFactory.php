<?php

namespace Database\Factories;

use App\Models\StrategyAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StrategyAccount>
 */
class StrategyAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'label' => 'Akun strategi uji',
            'kind' => 'experiment',
            'starting_balance' => 10000,
            'cash' => 10000,
            'is_active' => true,
            'started_at' => now(),
        ];
    }
}
