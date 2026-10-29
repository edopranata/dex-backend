<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Services\Trading\PortfolioSummary;
use App\Services\Trading\TradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function show(Request $request, PortfolioSummary $summary): JsonResponse
    {
        $user = $request->user()->refresh();
        $positions = $user->positions()->with('token')->where('quantity', '>', 0)->get();

        return response()->json([
            ...$summary->for($user, $positions),
            'positions' => PositionResource::collection($positions),
        ]);
    }

    /**
     * Figures only, for the sidebar (polled).
     */
    public function summary(Request $request, PortfolioSummary $summary): JsonResponse
    {
        return response()->json($summary->for($request->user()->refresh()));
    }

    public function reset(Request $request, TradingService $trading): JsonResponse
    {
        $trading->reset($request->user());

        return response()->json(['message' => 'Portofolio direset ke saldo awal.']);
    }
}
