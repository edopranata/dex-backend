<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Services\Trading\TradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->refresh();
        $positions = $user->positions()->with('token')->where('quantity', '>', 0)->get();

        $holdingsValue = $positions->sum(fn ($p): float => $p->quantity * (float) $p->token->price_usd);
        $costBasis = $positions->sum(fn ($p): float => $p->quantity * $p->avg_price);
        $equity = $user->balance_usd + $holdingsValue;
        $startingBalance = config('market.simulator.starting_balance');
        $filled = $user->orders()->where('status', OrderStatus::Filled);

        return response()->json([
            'balance_usd' => $user->balance_usd,
            'holdings_value_usd' => $holdingsValue,
            'equity_usd' => $equity,
            'starting_balance' => $startingBalance,
            'total_pnl' => $equity - $startingBalance,
            'total_pnl_pct' => ($equity - $startingBalance) / $startingBalance * 100,
            'unrealized_pnl' => $holdingsValue - $costBasis,
            'realized_pnl' => (float) $user->positions()->sum('realized_pnl'),
            'fees_paid' => (float) (clone $filled)->sum('fee_usd'),
            'trades' => (clone $filled)->count(),
            'positions' => PositionResource::collection($positions),
        ]);
    }

    public function reset(Request $request, TradingService $trading): JsonResponse
    {
        $trading->reset($request->user());

        return response()->json(['message' => 'Portofolio direset ke saldo awal.']);
    }
}
