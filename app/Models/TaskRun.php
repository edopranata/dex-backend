<?php

namespace App\Models;

use Database\Factories\TaskRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution of a scheduled or manually triggered artisan command.
 */
#[Fillable(['command', 'schedule', 'trigger', 'status', 'exit_code', 'duration_ms', 'output', 'user_id', 'started_at', 'finished_at'])]
class TaskRun extends Model
{
    /** @use HasFactory<TaskRunFactory> */
    use HasFactory, MassPrunable;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(7));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
