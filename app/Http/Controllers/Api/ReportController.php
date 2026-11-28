<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\StrategyAccount;
use App\Models\StrategyPosition;
use App\Models\User;
use App\Services\Analysis\AccountReport;
use App\Services\Analysis\AccountSelection;
use App\Services\Analysis\PerformanceReport;
use App\Services\Analysis\StrategyVersion;
use App\Services\Strategies\StrategyBroker;
use App\Services\Trading\PositionCards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * One page of a shadow signal group from the performance report, with each signal's levels, result and market.
     */
    public function shadowSignals(Request $request, PerformanceReport $report): JsonResponse
    {
        $data = $request->validate([
            'group' => ['required', 'string', Rule::in(PerformanceReport::SHADOW_GROUPS)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json($report->shadowSignals($data['group'], (int) ($data['page'] ?? 1)));
    }

    /**
     * One auto-follow account as a whole, on its own lots: criteria, funnel, exits, weeks and every lot.
     */
    /**
     * The auto-follow accounts against the selection limits (Seleksi akun page).
     */
    public function selection(AccountSelection $selection): JsonResponse
    {
        return response()->json($selection->build());
    }

    /**
     * The strategy accounts from FutureSim with equity and positions (Akun strategi page).
     */
    public function strategyAccounts(StrategyBroker $broker): JsonResponse
    {
        $accounts = StrategyAccount::query()->orderBy('id')->get();
        $config = config('market.strategy_accounts.strategies');

        return response()->json([
            'enabled' => (bool) config('market.strategy_accounts.enabled'),
            'accounts' => $accounts->map(function (StrategyAccount $account) use ($broker, $config): array {
                $equity = $broker->equity($account);
                $closed = StrategyPosition::query()->where('strategy_account_id', $account->id)->where('status', StrategyPosition::CLOSED);

                return [
                    'key' => $account->key,
                    'label' => $account->label,
                    'kind' => $account->kind,
                    'is_active' => $account->is_active && isset($config[$account->key]),
                    'equity' => round($equity, 2),
                    'return_pct' => round(($equity / $account->starting_balance - 1) * 100, 2),
                    'cash' => round($account->cash, 2),
                    'fees_paid' => round($account->fees_paid, 2),
                    'open' => StrategyPosition::query()->where('strategy_account_id', $account->id)->whereIn('status', [StrategyPosition::OPEN, StrategyPosition::PENDING])->count(),
                    'closed' => (clone $closed)->count(),
                    'wins' => (clone $closed)->where('net_pnl', '>', 0)->count(),
                    'net_pnl' => round((float) (clone $closed)->sum('net_pnl'), 2),
                    'started_at' => $account->started_at->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    /**
     * One strategy account's positions, waiting and open first, then the last that ended.
     */
    public function strategyPositions(string $key, StrategyBroker $broker): JsonResponse
    {
        $account = StrategyAccount::query()->where('key', $key)->firstOrFail();
        $positions = StrategyPosition::query()->where('strategy_account_id', $account->id)->with('token:id,symbol,slug,chain_id,price_usd,price_updated_at')
            ->orderByRaw("status in ('open', 'pending') desc")->latest('opened_at')->limit(150)->get();

        return response()->json([
            'key' => $account->key,
            'label' => $account->label,
            'positions' => $positions->map(function (StrategyPosition $position) use ($broker): array {
                $price = $position->status === StrategyPosition::OPEN ? $broker->lastPrice($position->token, $position->entry_price) : null;
                $risk = (float) ($position->state['initial_risk'] ?? 0);

                return [
                    'id' => $position->id,
                    'token' => ['symbol' => $position->token->symbol, 'slug' => $position->token->slug, 'chain' => $position->token->chain_id],
                    'status' => $position->status,
                    'entry_price' => $position->entry_price,
                    'stop' => $position->stop,
                    'initial_stop' => $position->state['initial_stop'] ?? null,
                    'take_profit' => $position->take_profit,
                    'amount_usd' => round($position->cost_basis, 2),
                    'price' => $price,
                    'unrealized_usd' => $price === null ? null : round($position->remaining * ($price - $position->entry_price) - $position->costs, 2),
                    'net_pnl' => round($position->net_pnl, 2),
                    'r' => $position->status === StrategyPosition::CLOSED && $risk > 0 ? round($position->net_pnl / $risk, 2) : null,
                    'adds' => $position->state['adds_done'] ?? null,
                    'entry_reason' => $position->entry_reason,
                    'exit_reason' => $position->exit_reason,
                    'opened_at' => $position->opened_at->toIso8601String(),
                    'closed_at' => $position->closed_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

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
    public function accountPortfolio(Request $request, string $key, PositionCards $cards): JsonResponse
    {
        $account = User::autoFollower($key);

        return response()->json([
            'positions' => $cards->present($account, $account->positions()->with('token')->where('quantity', '>', 0)->get(), $request),
            'pending' => OrderResource::collection($account->orders()->with('token')->where('status', OrderStatus::Pending)->latest()->latest('id')->get()),
            'orders' => OrderResource::collection($account->orders()->with('token')->where('status', '!=', OrderStatus::Pending)->orderByRaw('COALESCE(filled_at, created_at) DESC')->latest('id')->paginate(self::ORDERS_PER_PAGE))->response()->getData(true),
        ]);
    }
}
