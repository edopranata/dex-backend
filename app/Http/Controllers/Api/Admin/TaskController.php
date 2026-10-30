<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunAdminCommand;
use App\Models\TaskRun;
use App\Services\Admin\TaskRecorder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /**
     * Every task in the current schedule (routes/console.php) with its recorded runs, plus recent history.
     * Retired or renamed schedule entries drop off; tasks that have not run yet still show.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'command' => ['sometimes', 'nullable', 'string', 'max:191'],
            'status' => ['sometimes', 'nullable', Rule::in(['success', 'failed', 'skipped', 'running', 'queued'])],
        ]);

        $events = collect($this->scheduledEvents())->keyBy(fn (Event $event): string => TaskRecorder::commandName($event));
        $commands = $events->keys()->all();

        $latest = TaskRun::query()
            ->whereIn('id', TaskRun::query()->select(DB::raw('MAX(id)'))->where('trigger', 'schedule')->whereIn('command', $commands)->groupBy('command'))
            ->get()
            ->keyBy('command');
        $stats = TaskRun::query()
            ->where('trigger', 'schedule')
            ->whereIn('command', $commands)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw("command, COUNT(*) as runs_24h, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failures_24h, AVG(duration_ms) as avg_ms")
            ->groupBy('command')
            ->get()
            ->keyBy('command');

        $tasks = $events->sortKeys()->map(function (Event $event, string $command) use ($latest, $stats): array {
            $run = $latest[$command] ?? null;

            return [
                'command' => $command,
                'schedule' => TaskRecorder::scheduleLabel($event),
                'last_status' => $run?->status,
                'last_run_at' => $run ? ($run->finished_at ?? $run->created_at) : null,
                'last_duration_ms' => $run?->duration_ms,
                // In the schedule's own timezone, so daily and every-6-hours tasks show the real next run.
                'next_run_at' => rescue(fn () => $event->nextRunDate()->toIso8601String(), null, report: false),
                'runs_24h' => (int) ($stats[$command]->runs_24h ?? 0),
                'failures_24h' => (int) ($stats[$command]->failures_24h ?? 0),
                'avg_duration_ms' => isset($stats[$command]) ? (int) $stats[$command]->avg_ms : null,
                'can_run' => array_key_exists($command, RunAdminCommand::ALLOWED),
            ];
        })->values();

        $runs = TaskRun::query()
            ->with('user:id,name')
            ->when($filters['command'] ?? null, fn ($q, $command) => $q->where('command', $command))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'tasks' => $tasks,
            'runs' => $runs,
            'allowed' => array_keys(RunAdminCommand::ALLOWED),
        ]);
    }

    /**
     * The schedule is defined in routes/console.php, which only the console kernel loads.
     *
     * @return list<Event>
     */
    private function scheduledEvents(): array
    {
        app(ConsoleKernel::class)->bootstrap();

        return app(Schedule::class)->events();
    }

    public function run(Request $request): JsonResponse
    {
        $command = $request->validate(['command' => ['required', Rule::in(array_keys(RunAdminCommand::ALLOWED))]])['command'];

        $run = TaskRun::create([
            'command' => $command,
            'trigger' => 'manual',
            'status' => 'queued',
            'user_id' => $request->user()->id,
        ]);

        RunAdminCommand::dispatch($run);

        return response()->json(['data' => $run], 202);
    }
}
