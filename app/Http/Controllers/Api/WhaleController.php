<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderSide;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhaleTradeResource;
use App\Models\WhaleTrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WhaleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'token_id' => ['sometimes', 'integer'],
            'side' => ['sometimes', Rule::enum(OrderSide::class)],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
        ]);

        $query = WhaleTrade::query()
            ->with('token')
            ->where('traded_at', '>=', now()->subHours($filters['hours'] ?? 24))
            ->when($filters['token_id'] ?? null, fn ($q, $id) => $q->where('token_id', $id))
            ->when($filters['side'] ?? null, fn ($q, $side) => $q->where('side', $side));

        $summary = (clone $query)
            ->toBase()
            ->select('token_id', DB::raw("SUM(CASE WHEN side = 'buy' THEN amount_usd ELSE 0 END) as buy_usd"), DB::raw("SUM(CASE WHEN side = 'sell' THEN amount_usd ELSE 0 END) as sell_usd"), DB::raw('COUNT(*) as trades'))
            ->groupBy('token_id')
            ->get();

        return WhaleTradeResource::collection($query->latest('traded_at')->paginate(50))
            ->additional(['summary' => $summary]);
    }
}
