<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analysis\PerformanceReport;
use App\Services\Analysis\StrategyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Performance of one strategy version: the current one by default, or "all" for every version.
     */
    public function performance(Request $request, PerformanceReport $report): JsonResponse
    {
        $version = $request->validate(['version' => ['sometimes', 'string', 'max:40']])['version'] ?? StrategyVersion::current();

        return response()->json($report->build($version === 'all' ? null : $version));
    }
}
