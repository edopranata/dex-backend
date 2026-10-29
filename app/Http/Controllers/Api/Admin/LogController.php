<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\LogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogController extends Controller
{
    public function index(Request $request, LogReader $logs): JsonResponse
    {
        $filters = $request->validate([
            'file' => ['sometimes', 'string'],
            'level' => ['sometimes', 'nullable', Rule::in(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:10', 'max:1000'],
        ]);

        $files = $logs->files();
        $file = $filters['file'] ?? ($files[0]['name'] ?? null);

        return response()->json([
            'files' => $files,
            'file' => $file,
            ...($file ? rescue(fn () => $logs->entries($file, $filters['level'] ?? null, $filters['search'] ?? null, $filters['limit'] ?? 200), fn () => abort(404), report: false) : ['entries' => [], 'truncated' => false]),
        ]);
    }

    public function download(string $name, LogReader $logs): BinaryFileResponse
    {
        return response()->download(rescue(fn () => $logs->path($name), fn () => abort(404), report: false), $name, ['Content-Type' => 'text/plain']);
    }
}
