<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

/**
 * The numbers the Horizon dashboard shows, for places outside it (the Telegram /horizon command).
 */
class HorizonStatus
{
    public function __construct(
        private MasterSupervisorRepository $masters,
        private WorkloadRepository $workload,
        private MetricsRepository $metrics,
    ) {}

    /**
     * @return array{status: string, queues: list<array{name: string, length: int, wait: int, processes: int}>, jobs_per_minute: float, failed_24h: int}
     */
    public function summary(): array
    {
        $masters = collect($this->masters->all());

        return [
            'status' => match (true) {
                $masters->isEmpty() => 'inactive',
                $masters->contains(fn ($master): bool => $master->status === 'paused') => 'paused',
                default => 'running',
            },
            'queues' => collect($this->workload->get())->map(fn (array $queue): array => [
                'name' => (string) $queue['name'],
                'length' => (int) $queue['length'],
                'wait' => (int) $queue['wait'],
                'processes' => (int) $queue['processes'],
            ])->values()->all(),
            'jobs_per_minute' => round((float) $this->metrics->jobsProcessedPerMinute(), 1),
            'failed_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
        ];
    }
}
