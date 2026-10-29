<?php

namespace App\Services\Admin;

use App\Models\TaskRun;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Records every scheduler execution so the admin panel can show history and failures.
 */
class TaskRecorder
{
    public function finished(ScheduledTaskFinished $event): void
    {
        $failed = ($event->task->exitCode ?? 0) !== 0;
        $this->record($event->task, $failed ? 'failed' : 'success', (int) round($event->runtime * 1000));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, 'failed', null, $event->exception->getMessage());
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->record($event->task, 'skipped', 0);
    }

    /**
     * "'/usr/local/bin/php' 'artisan' market:prices" -> "market:prices".
     */
    public static function commandName(Event $task): string
    {
        $command = (string) ($task->command ?? $task->description ?? 'closure');

        return trim(preg_replace("/^.*'artisan'\s+/", '', $command) ?? $command);
    }

    public static function scheduleLabel(Event $task): string
    {
        $repeat = property_exists($task, 'repeatSeconds') && $task->repeatSeconds ? " ({$task->repeatSeconds}s)" : '';

        return $task->expression.$repeat;
    }

    private function record(Event $task, string $status, ?int $durationMs, ?string $output = null): void
    {
        if ($status === 'failed') {
            $command = self::commandName($task);
            app(TelegramNotifier::class)->system("Jadwal gagal: {$command}", $output ?? "Exit code {$task->exitCode}", "task:{$command}");
        }

        try {
            TaskRun::create([
                'command' => mb_substr(self::commandName($task), 0, 191),
                'schedule' => self::scheduleLabel($task),
                'trigger' => 'schedule',
                'status' => $status,
                'exit_code' => $task->exitCode,
                'duration_ms' => $durationMs,
                'output' => $output ? mb_substr($output, 0, 5000) : null,
                'started_at' => $durationMs !== null ? now()->subMilliseconds($durationMs) : null,
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let bookkeeping break the scheduler (e.g. before migrations ran).
        }
    }
}
