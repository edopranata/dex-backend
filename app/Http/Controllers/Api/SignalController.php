<?php

namespace App\Http\Controllers\Api;

use App\Enums\SignalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FollowSignalRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\SignalResource;
use App\Models\Signal;
use App\Services\Trading\TradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SignalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'token_id' => ['sometimes', 'integer'],
        ]);

        return SignalResource::collection(
            Signal::query()
                ->with('token')
                ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->open())
                ->when(($filters['status'] ?? null) === 'closed', fn ($q) => $q->whereNotNull('closed_at'))
                ->when($filters['token_id'] ?? null, fn ($q, $id) => $q->where('token_id', $id))
                ->latest()
                ->paginate(30),
        );
    }

    /**
     * Track record of every signal the engine has produced.
     */
    public function stats(): JsonResponse
    {
        $closed = Signal::query()->whereNotNull('closed_at')->get();
        $entered = $closed->whereNotNull('entry_price');
        $wins = $entered->filter(fn (Signal $s): bool => $s->result_r > 0);

        return response()->json([
            'total' => Signal::query()->count(),
            'open' => Signal::query()->open()->count(),
            'closed' => $closed->count(),
            'entered' => $entered->count(),
            'not_entered' => $closed->whereIn('status', [SignalStatus::Expired, SignalStatus::Invalidated])->whereNull('entry_price')->count(),
            'wins' => $wins->count(),
            'losses' => $entered->filter(fn (Signal $s): bool => $s->result_r < 0)->count(),
            'win_rate' => $entered->isEmpty() ? null : round($wins->count() / $entered->count() * 100, 1),
            'total_r' => round((float) $entered->sum('result_r'), 2),
            'avg_r' => $entered->isEmpty() ? null : round((float) $entered->avg('result_r'), 2),
            'tp_hits' => [
                'tp1' => $entered->where('max_tp_hit', '>=', 1)->count(),
                'tp2' => $entered->where('max_tp_hit', '>=', 2)->count(),
                'tp3' => $entered->where('max_tp_hit', '>=', 3)->count(),
            ],
        ]);
    }

    public function follow(FollowSignalRequest $request, Signal $signal, TradingService $trading): AnonymousResourceCollection
    {
        if (! $signal->status->isOpen()) {
            throw ValidationException::withMessages(['signal' => 'Sinyal ini sudah ditutup.']);
        }

        $orders = $trading->followSignal($request->user(), $signal->load('token'), (float) $request->validated('amount_usd'));

        return OrderResource::collection($orders);
    }
}
