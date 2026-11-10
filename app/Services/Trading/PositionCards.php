<?php

namespace App\Services\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Resources\PositionResource;
use App\Models\Order;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a portfolio card shows for each open position: the exit plan (stop and targets, with what already
 * filled) and the earlier trades in the same token. A trade runs from the first buy while flat until the
 * position is empty again, so manual buys and sells group the same way as signal lots.
 */
class PositionCards
{
    /** Earlier trades listed per token; the order history holds the rest. */
    public const HISTORY_LIMIT = 10;

    /**
     * @param  Collection<int, Position>  $positions  With their token loaded.
     * @return list<array<string, mixed>>
     */
    public function present(User $user, Collection $positions, Request $request): array
    {
        return $positions->map(function (Position $position) use ($user, $request): array {
            $trades = $this->trades($user, $position->token_id);
            $open = $trades->last() !== null && $trades->last()['closed_at'] === null ? $trades->pop() : null;

            return [
                ...(new PositionResource($position))->resolve($request),
                'plan' => $this->plan($user, $position, $open),
                'history' => $trades->reverse()->take(self::HISTORY_LIMIT)->map(fn (array $trade): array => $this->summary($trade))->values()->all(),
                'history_total' => $trades->count(),
            ];
        })->values()->all();
    }

    /**
     * Filled orders of one token, oldest first, cut into trades.
     *
     * @return Collection<int, array{buys: list<Order>, sells: list<Order>, closed_at: Carbon|null}>
     */
    private function trades(User $user, int $tokenId): Collection
    {
        $orders = $user->orders()
            ->where('token_id', $tokenId)
            ->where('status', OrderStatus::Filled)
            ->orderBy('filled_at')
            ->orderBy('id')
            ->get();

        $trades = collect();
        $current = null;
        $held = 0.0;
        $peak = 0.0;

        foreach ($orders as $order) {
            if ($order->side === OrderSide::Buy) {
                $current ??= ['buys' => [], 'sells' => [], 'closed_at' => null];
                $current['buys'][] = $order;
                $held += (float) $order->filled_quantity;
                $peak = max($peak, $held);

                continue;
            }

            if ($current === null) {
                continue;
            }

            $current['sells'][] = $order;
            $held -= (float) $order->filled_quantity;

            // The trading service closes a position on a remainder this small; thirds never add up exactly.
            if ($held <= $peak * 1e-6) {
                $current['closed_at'] = $order->filled_at;
                $trades->push($current);
                $current = null;
                $held = 0.0;
                $peak = 0.0;
            }
        }

        if ($current !== null) {
            $trades->push($current);
        }

        return $trades;
    }

    /**
     * Stop and targets of the open trade, current and filled. The original stop is the signal's, or the
     * stop as placed when it has not moved.
     *
     * @param  array{buys: list<Order>, sells: list<Order>, closed_at: mixed}|null  $open
     * @return array<string, mixed>
     */
    private function plan(User $user, Position $position, ?array $open): array
    {
        $buys = collect($open['buys'] ?? []);
        $buyIds = $buys->pluck('id')->all();

        $exits = $user->orders()
            ->where('token_id', $position->token_id)
            ->where('side', OrderSide::Sell)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->whereIn('parent_id', $buyIds)->whereIn('status', [OrderStatus::Pending, OrderStatus::Filled]))
                ->orWhere(fn ($q) => $q->whereNull('parent_id')->where('status', OrderStatus::Pending)))
            ->orderBy('id')
            ->get();

        $stop = $exits->where('type', OrderType::StopLoss)->where('status', OrderStatus::Pending)->last();
        $signalBuy = $buys->first(fn (Order $buy): bool => $buy->exit_plan === 'signal' && $buy->signal !== null);
        $originalStop = $signalBuy?->signal->stop_loss
            ?? ($stop !== null && ! str_contains((string) $stop->note, 'breakeven') ? $stop->trigger_price : null);

        $targets = $exits
            ->filter(fn (Order $o): bool => $o->type === OrderType::TakeProfit || ($o->type === OrderType::Limit && $o->status === OrderStatus::Pending))
            ->sortBy('trigger_price')
            ->values();

        return [
            'opened_at' => $buys->first()?->filled_at?->toIso8601String(),
            'signal_id' => $signalBuy?->signal_id,
            'lot_id' => $signalBuy?->id,
            'entry' => (float) $position->avg_price,
            'stop' => $stop?->trigger_price,
            'stop_original' => $originalStop !== null ? (float) $originalStop : null,
            'stop_breakeven' => $stop !== null && str_contains((string) $stop->note, 'breakeven'),
            'targets' => $targets->map(fn (Order $o, int $i): array => [
                'label' => self::label($o) ?? ($o->type === OrderType::Limit ? 'Limit' : ($targets->count() > 1 ? 'TP'.($i + 1) : 'TP')),
                'price' => (float) $o->trigger_price,
                'filled' => $o->status === OrderStatus::Filled,
                'filled_at' => $o->filled_at?->toIso8601String(),
                'filled_price' => $o->filled_price,
            ])->all(),
        ];
    }

    /**
     * @param  array{buys: list<Order>, sells: list<Order>, closed_at: Carbon|null}  $trade
     * @return array<string, mixed>
     */
    private function summary(array $trade): array
    {
        $buys = collect($trade['buys']);
        $sells = collect($trade['sells']);
        $invested = (float) $buys->sum('total_usd');
        $quantity = (float) $buys->sum('filled_quantity');
        $pnl = (float) $sells->sum('realized_pnl');
        $first = $buys->first();

        return [
            'id' => $first->id,
            'opened_at' => $first->filled_at?->toIso8601String(),
            'closed_at' => $trade['closed_at']?->toIso8601String(),
            'signal_id' => $first->signal_id,
            'lot_id' => $first->exit_plan === 'signal' && $first->signal_id !== null ? $first->id : null,
            'buys' => $buys->count(),
            'entry' => $quantity > 0 ? $invested / $quantity : null,
            'invested_usd' => $invested,
            'pnl' => $pnl,
            'pnl_pct' => $invested > 0 ? $pnl / $invested * 100 : null,
            'exits' => $sells->map(fn (Order $o): array => [
                'label' => self::label($o) ?? match (true) {
                    $o->type === OrderType::StopLoss => str_contains((string) $o->note, 'breakeven') ? 'SL di entry' : 'SL',
                    $o->type === OrderType::TakeProfit => 'TP',
                    $o->type === OrderType::Limit => 'Limit',
                    str_starts_with((string) $o->note, 'Ditutup otomatis') => 'Tutup otomatis',
                    default => 'Jual',
                },
                'type' => $o->type->value,
                'at' => $o->filled_at?->toIso8601String(),
                'price' => $o->filled_price,
                'pnl' => $o->realized_pnl,
            ])->values()->all(),
        ];
    }

    /** Which target of a signal an exit is: the system writes it at the start of the note. */
    private static function label(Order $order): ?string
    {
        return preg_match('/^TP\d/', (string) $order->note, $m) === 1 ? $m[0] : null;
    }
}
