<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceNote;
use App\Services\Admin\ServiceDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → General Information: the third-party services the app depends on.
 */
class InfoController extends Controller
{
    public function index(ServiceDirectory $directory): JsonResponse
    {
        return response()->json([
            'app_url' => config('app.url'),
            'groups' => $directory->groups(),
        ]);
    }

    public function updateNote(Request $request, string $service, ServiceDirectory $directory): JsonResponse
    {
        abort_unless(in_array($service, $directory->keys(), true), 404);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

        if (blank($note)) {
            ServiceNote::query()->where('service', $service)->delete();
        } else {
            ServiceNote::query()->updateOrCreate(['service' => $service], ['note' => trim($note), 'updated_by' => $request->user()->id]);
        }

        return response()->json(['note' => blank($note) ? null : trim($note)]);
    }
}
