<?php

namespace App\Jobs;

use App\Models\TaskRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Runs an allowlisted artisan command requested from the admin panel and records the output.
 */
class RunAdminCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * Commands admins may trigger from the panel, with the arguments they run with.
     *
     * @var array<string, array<string, mixed>>
     */
    public const ALLOWED = [
        'market:prices' => [],
        'market:snapshots' => [],
        'market:candles' => [],
        'market:whales' => [],
        'market:news' => [],
        'market:security' => [],
        'market:discover' => ['--sync' => true],
        'market:analyze' => [],
        'market:watchlist' => [],
        'market:bootstrap' => [],
        'model:prune' => [],
        'queue:prune-failed' => ['--hours' => 168],
    ];

    public function __construct(public TaskRun $run) {}

    public function handle(): void
    {
        $start = microtime(true);
        $this->run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $exitCode = Artisan::call($this->run->command, self::ALLOWED[$this->run->command] ?? []);
            $output = Artisan::output();
        } catch (Throwable $e) {
            $exitCode = 1;
            $output = $e->getMessage();
        }

        $this->run->update([
            'status' => $exitCode === 0 ? 'success' : 'failed',
            'exit_code' => $exitCode,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'output' => mb_substr(trim($output), -20000),
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->run->update(['status' => 'failed', 'output' => mb_substr($exception->getMessage(), 0, 5000), 'finished_at' => now()]);
    }
}
