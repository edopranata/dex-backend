<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Token;
use App\Services\Trading\TradingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Order history with filters; "summary" covers every matching order, not just the current page.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'token_id' => ['sometimes', 'integer'],
            'side' => ['sometimes', Rule::enum(OrderSide::class)],
            'type' => ['sometimes', Rule::enum(OrderType::class)],
            'scope' => ['sometimes', Rule::in(['open_positions'])],
            'source' => ['sometimes', Rule::in(['signal', 'manual'])],
            'result' => ['sometimes', Rule::in(['profit', 'loss'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tz' => ['sometimes', 'timezone:all'],
            'per_page' => ['sometimes', 'integer', 'min:10', 'max:200'],
        ]);

        $user = $request->user();
        $timezone = $filters['tz'] ?? config('app.timezone');
        // Same moment the table shows: when the order filled, or when it was placed if it never did.
        $orderTime = DB::raw('COALESCE(filled_at, created_at)');

        $query = $user->orders()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['token_id'] ?? null, fn ($q, $id) => $q->where('token_id', $id))
            ->when($filters['side'] ?? null, fn ($q, $side) => $q->where('side', $side))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when(($filters['scope'] ?? null) === 'open_positions', fn ($q) => $q->whereIn(
                'token_id',
                $user->positions()->where('quantity', '>', 0)->select('token_id'),
            ))
            ->when($filters['source'] ?? null, fn ($q, $source) => $source === 'signal' ? $q->whereNotNull('signal_id') : $q->whereNull('signal_id'))
            ->when($filters['result'] ?? null, fn ($q, $result) => $q->where('realized_pnl', $result === 'profit' ? '>' : '<', 0))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where($orderTime, '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where($orderTime, '<=', Carbon::parse($to, $timezone)->endOfDay()->utc()));

        $summary = (clone $query)->toBase()->selectRaw('COUNT(*) as orders, COALESCE(SUM(realized_pnl), 0) as realized_pnl, COALESCE(SUM(fee_usd), 0) as fees')
            ->selectRaw('SUM(CASE WHEN realized_pnl > 0 THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN realized_pnl < 0 THEN 1 ELSE 0 END) as losses')
            ->first();

        return OrderResource::collection(
            $query->with('token')->latest()->latest('id')->paginate($filters['per_page'] ?? 50),
        )->additional(['summary' => [
            'orders' => (int) $summary->orders,
            'realized_pnl' => round((float) $summary->realized_pnl, 2),
            'fees' => round((float) $summary->fees, 2),
            'wins' => (int) $summary->wins,
            'losses' => (int) $summary->losses,
        ]]);
    }

    public function store(StoreOrderRequest $request, TradingService $trading): OrderResource
    {
        $token = Token::findOrFail($request->validated('token_id'));

        $order = $trading->place($request->user(), $token, $request->validated());

        return new OrderResource($order->load('token'));
    }

    public function destroy(Request $request, Order $order, TradingService $trading): OrderResource
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return new OrderResource($trading->cancel($order));
    }
}
