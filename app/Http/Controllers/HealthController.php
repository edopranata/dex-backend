<?php

namespace App\Http\Controllers;

use App\Services\Admin\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public health endpoint for the external uptime monitor (UptimeRobot): 503 when the database,
 * Redis or the scheduler is down, so an outage behind a still-running web server is noticed too.
 */
class HealthController extends Controller
{
    public const LAST_MONITOR_CHECK = 'health:last-monitor-check';

    public function __invoke(Request $request, HealthCheck $health): JsonResponse
    {
        $checks = $health->run();
        $ok = ! in_array(false, $checks, true);

        // Lets Admin → General Information show that the external monitor is actually polling.
        if (preg_match('/UptimeRobot/i', (string) $request->userAgent()) === 1) {
            rescue(fn () => Cache::put(self::LAST_MONITOR_CHECK, ['at' => now()->toIso8601String(), 'ok' => $ok], now()->addDay()), report: false);
        }

        return response()->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503);
    }
}
