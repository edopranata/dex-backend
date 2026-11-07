<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PositionResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Analysis\AccountReport;
use App\Services\Analysis\PerformanceReport;
use App\Services\Analysis\StrategyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private const ORDERS_PER_PAGE = 20;

    /**
     * Performance of one strategy version: the current one by default, or "all" for every version.
     */
    public function performance(Request $request, PerformanceReport $report): JsonResponse
    {
        $version = $request->validate(['version' => ['sometimes', 'string', 'max:40']])['version'] ?? StrategyVersion::current();

        return response()->json($report->build($version === 'all' ? null : $version));
    }

    /**
     * One auto-follow account as a whole, on its own lots: criteria, funnel, exits, weeks and every lot.
     */
    public function accountReport(Request $request, string $key, AccountReport $report): JsonResponse
    {
        $version = $request->validate(['version' => ['sometimes', 'string', 'max:40']])['version'] ?? StrategyVersion::current();

        return response()->json($report->build($key, $version === 'all' ? null : $version));
    }

    /**
     * One auto-follow lot for the token chart: the signal's levels and every fill, so it shows when the lot
     * was bought and sold. Only lots of the auto-follow accounts, which the reports already show.
     */
    public function lot(Order $order): JsonResponse
    {
        $order->load(['user', 'token', 'signal']);
        abort_unless($order->side === OrderSide::Buy && $order->signal !== null && $order->user?->is_system, 404);

        $key = collect(User::AUTO_FOLLOW_ACCOUNTS)->search(fn (array $definition): bool => $definition['email'] === $order->user->email);
        $exits = Order::query()
            ->where('user_id', $order->user_id)
            ->where('side', OrderSide::Sell)
            ->where(fn ($query) => $query->where('parent_id', $order->id)->orWhere(fn ($q) => $q->whereNull('parent_id')->where('signal_id', $order->signal_id)))
            ->orderBy('id')
            ->get();
        $fill = fn (Order $o): array => [
            'id' => $o->id,
            'at' => $o->filled_at?->toIso8601String(),
            'side' => $o->side->value,
            'type' => $o->type->value,
            'price' => (float) $o->filled_price,
            'quantity' => (float) $o->filled_quantity,
            'note' => $o->note,
        ];
        $signal = $order->signal;

        return response()->json([
            'id' => $order->id,
            'account' => $key === false ? null : ['key' => $key, 'label' => User::AUTO_FOLLOW_ACCOUNTS[$key]['label']],
            'token' => ['symbol' => $order->token->symbol, 'slug' => $order->token->slug],
            'signal' => [
                'id' => $signal->id,
                'recommendation' => $signal->recommendation->value,
                'status' => $signal->status->value,
                'version' => $signal->strategy_version,
                'entry_low' => $signal->entry_low,
                'entry_high' => $signal->entry_high,
                'stop_loss' => $signal->stop_loss,
                'take_profits' => $signal->takeProfits(),
            ],
            'stop' => $exits->firstWhere(fn (Order $o): bool => $o->type === OrderType::StopLoss && $o->status === OrderStatus::Pending)?->trigger_price,
            'fills' => [$fill($order), ...$exits->where('status', OrderStatus::Filled)->sortBy('filled_at')->map($fill)->values()->all()],
        ]);
    }

    /**
     * What one auto-follow account holds and has done. Read-only: these accounts are the test itself, so
     * nothing here can cancel an order or reset a balance.
     */
    public function accountPortfolio(string $key): JsonResponse
    {
        $account = User::autoFollower($key);

        return response()->json([
            'positions' => PositionResource::collection($account->positions()->with('token')->where('quantity', '>', 0)->get()),
            'pending' => OrderResource::collection($account->orders()->with('token')->where('status', OrderStatus::Pending)->latest()->latest('id')->get()),
            'orders' => OrderResource::collection($account->orders()->with('token')->where('status', '!=', OrderStatus::Pending)->orderByRaw('COALESCE(filled_at, created_at) DESC')->latest('id')->paginate(self::ORDERS_PER_PAGE))->response()->getData(true),
        ]);
    }
}
