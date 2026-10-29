<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SystemInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemController extends Controller
{
    public function index(SystemInfo $system): JsonResponse
    {
        $failed = DB::table('failed_jobs')->latest('failed_at')->limit(50)->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($job): array => [
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'job' => json_decode($job->payload, true)['displayName'] ?? '?',
                'error' => Str::limit(strtok($job->exception, "\n"), 300),
                'failed_at' => $job->failed_at,
            ]);

        return response()->json([...$system->overview(), 'failed_jobs' => $failed]);
    }

    public function retryFailed(string $uuid): JsonResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => trim(Artisan::output())]);
    }

    public function forgetFailed(string $uuid): JsonResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return response()->json(['message' => trim(Artisan::output())]);
    }

    public function clearCache(): JsonResponse
    {
        Artisan::call('cache:clear');

        return response()->json(['message' => 'Cache aplikasi dibersihkan.']);
    }
}
