<?php

namespace Database\Factories;

use App\Models\TaskRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskRun>
 */
class TaskRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'command' => 'market:prices',
            'schedule' => '* * * * * (15s)',
            'trigger' => 'schedule',
            'status' => 'success',
            'exit_code' => 0,
            'duration_ms' => 400,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ];
    }
}
