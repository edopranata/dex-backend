<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\ContainerMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContainerMetricController extends Controller
{
    /**
     * Receives a sample from deploy/monitor.sh and answers with the interval to wait for the next one.
     */
    public function store(Request $request, ContainerMonitor $monitor): JsonResponse
    {
        $token = (string) config('market.monitoring.container_token');

        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $data = $request->validate([
            'inspect' => ['required', 'string', 'max:500000'],
            'stats' => ['nullable', 'string', 'max:500000'],
            'host' => ['required', 'array'],
            'host.memory_total' => ['required', 'integer', 'min:0'],
            'host.memory_available' => ['required', 'integer', 'min:0'],
            'host.disk_total' => ['required', 'integer', 'min:0'],
            'host.disk_used' => ['required', 'integer', 'min:0'],
            'host.load' => ['required', 'numeric', 'min:0'],
            'host.cores' => ['required', 'integer', 'min:1'],
        ]);

        $monitor->record($data['inspect'], (string) ($data['stats'] ?? ''), $data['host']);

        return response()->json(['interval_seconds' => $monitor->intervalSeconds()]);
    }
}
