<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Token;
use App\Services\Trading\TradingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(OrderStatus::class)],
            'token_id' => ['sometimes', 'integer'],
        ]);

        return OrderResource::collection(
            $request->user()->orders()
                ->with('token')
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filters['token_id'] ?? null, fn ($q, $id) => $q->where('token_id', $id))
                ->latest()
                ->paginate(50),
        );
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
