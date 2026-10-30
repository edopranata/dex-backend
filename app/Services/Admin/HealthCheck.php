<?php

namespace App\Services\Admin;

use App\Models\TaskRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Checks behind /health (polled by UptimeRobot) and the Healthchecks.io heartbeat.
 * /up stays a bare liveness check because the watchdog and deploys restart containers on it.
 */
class HealthCheck
{
    /**
     * @return array<string, bool>
     */
    public function run(bool $withHorizon = false): array
    {
        $checks = [
            'database' => $this->passes(fn (): bool => DB::select('select 1') !== []),
            'redis' => $this->passes(fn (): bool => (bool) Redis::connection()->ping()),
            'scheduler' => $this->passes(fn (): bool => TaskRun::query()
                ->where('trigger', 'schedule')
                ->where('created_at', '>=', now()->subMinutes(config('market.monitoring.scheduler_stale_minutes')))
                ->exists()),
        ];

        if ($withHorizon) {
            $checks['horizon'] = $this->passes(fn (): bool => app(HorizonStatus::class)->summary()['status'] === 'running');
        }

        return $checks;
    }

    /**
     * @param  callable(): bool  $check
     */
    private function passes(callable $check): bool
    {
        try {
            return $check();
        } catch (Throwable) {
            return false;
        }
    }
}
