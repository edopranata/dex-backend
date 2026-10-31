<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['sampled_at', 'name', 'project', 'service', 'state', 'health', 'exit_code', 'restarts', 'started_at', 'cpu_percent', 'memory_mb'])]
class ContainerMetric extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('sampled_at', '<', now()->subDays((int) config('market.monitoring.container_retention_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'started_at' => 'datetime',
            'exit_code' => 'integer',
            'restarts' => 'integer',
            'cpu_percent' => 'float',
            'memory_mb' => 'float',
        ];
    }
}
