<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunAdminCommand;
use App\Models\TaskRun;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /**
     * Per-command summary (from recorded runs) plus recent history.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'command' => ['sometimes', 'nullable', 'string', 'max:191'],
            'status' => ['sometimes', 'nullable', Rule::in(['success', 'failed', 'skipped', 'running', 'queued'])],
        ]);

        $latestIds = TaskRun::query()->select(DB::raw('MAX(id)'))->where('trigger', 'schedule')->groupBy('command');
        $since = now()->subDay();
        $stats = TaskRun::query()
            ->where('trigger', 'schedule')
            ->where('created_at', '>=', $since)
            ->selectRaw("command, COUNT(*) as runs_24h, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failures_24h, AVG(duration_ms) as avg_ms")
            ->groupBy('command')
            ->get()
            ->keyBy('command');

        $tasks = TaskRun::query()->whereIn('id', $latestIds)->orderBy('command')->get()->map(function (TaskRun $run) use ($stats): array {
            $expression = trim(preg_replace('/\s*\(\d+s\)$/', '', (string) $run->schedule));

            return [
                'command' => $run->command,
                'schedule' => $run->schedule,
                'last_status' => $run->status,
                'last_run_at' => $run->finished_at ?? $run->created_at,
                'last_duration_ms' => $run->duration_ms,
                'next_run_at' => rescue(fn () => CronExpression::isValidExpression($expression)
                    ? (new CronExpression($expression))->getNextRunDate(now()->setTimezone('Asia/Jakarta'))->format(DATE_ATOM)
                    : null, null, report: false),
                'runs_24h' => (int) ($stats[$run->command]->runs_24h ?? 0),
                'failures_24h' => (int) ($stats[$run->command]->failures_24h ?? 0),
                'avg_duration_ms' => isset($stats[$run->command]) ? (int) $stats[$run->command]->avg_ms : null,
                'can_run' => array_key_exists($run->command, RunAdminCommand::ALLOWED),
            ];
        });

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
