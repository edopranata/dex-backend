<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\BackupCooldownException;
use App\Services\Admin\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BackupController extends Controller
{
    public function index(BackupService $backups): JsonResponse
    {
        return response()->json([
            'data' => $backups->list(),
            'last_backup_at' => $backups->lastBackupAt()?->toIso8601String(),
            'manual_available_at' => $backups->manualAvailableAt()?->toIso8601String(),
            'cooldown_minutes' => config('market.backup.manual_cooldown_minutes'),
            'schedule' => 'Otomatis setiap hari pukul 02:30 WIB (cron server), disimpan 14 hari.',
            'offsite' => $backups->offsiteStatus(),
            'restore_check' => $backups->restoreCheck(),
        ]);
    }

    public function store(BackupService $backups): JsonResponse
    {
        try {
            $backup = $backups->create();
        } catch (BackupCooldownException $e) {
            return response()->json(['message' => $e->getMessage(), 'manual_available_at' => $e->availableAt->toIso8601String()], 429);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json(['data' => $backup], 201);
    }

    public function download(string $name, BackupService $backups): BinaryFileResponse
    {
        abort_unless(preg_match(BackupService::FILENAME_PATTERN, $name) === 1, 404);

        return response()->download(rescue(fn () => $backups->path($name), fn () => abort(404), report: false), $name, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    public function destroy(string $name, BackupService $backups): Response
    {
        rescue(fn () => $backups->delete($name), fn () => abort(404), report: false);

        return response()->noContent();
    }
}
