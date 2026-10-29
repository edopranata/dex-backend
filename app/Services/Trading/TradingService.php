<?php

namespace App\Services\Trading;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\Position;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\MarketData\PriceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paper trading against real DEX prices with swap fees and liquidity-based slippage.
 */
class TradingService
{
    /**
     * Quantities below this are treated as an empty position.
     */
    private const DUST = 1e-12;

    public function __construct(private PriceService $prices) {}

    /**
     * Place an order. Market orders fill immediately; the rest wait for their trigger price.
     *
     * @param  array{side: string, type: string, amount_usd?: float|null, quantity?: float|null, trigger_price?: float|null, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function place(User $user, Token $token, array $data, ?Signal $signal = null): Order
    {
        $side = OrderSide::from($data['side']);
        $type = OrderType::from($data['type']);

        if ($side === OrderSide::Sell && $this->positionQuantity($user, $token) <= self::DUST) {
            throw ValidationException::withMessages(['quantity' => "Anda tidak memiliki {$token->symbol}."]);
        }

        if ($side === OrderSide::Buy && $type === OrderType::Market && $user->balance_usd < (float) $data['amount_usd']) {
            throw ValidationException::withMessages(['amount_usd' => 'Saldo USD tidak cukup.']);
        }

        $order = $user->orders()->create([
            'token_id' => $token->id,
            'signal_id' => $signal?->id,
            'side' => $side,
            'type' => $type,
            'status' => OrderStatus::Pending,
            'amount_usd' => $side === OrderSide::Buy ? $data['amount_usd'] : null,
            'quantity' => $side === OrderSide::Sell ? $data['quantity'] : null,
            'trigger_price' => $type === OrderType::Market ? null : $data['trigger_price'],
            'note' => $data['note'] ?? null,
        ]);

        if ($type === OrderType::Market) {
            $this->prices->ensureFresh($token);

            if ($token->price_usd === null) {
                $this->reject($order, 'Harga tidak tersedia.');
            } else {
                $this->execute($order, $token->price_usd);
            }
        }

        return $order->refresh();
    }

    /**
     * Fill an order at the given market price inside a transaction with row locks.
     */
    public function execute(Order $order, float $marketPrice): Order
    {
        return DB::transaction(function () use ($order, $marketPrice): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::Pending) {
                return $order;
            }

            $user = User::query()->lockForUpdate()->findOrFail($order->user_id);
            $token = $order->token;
            $position = Position::query()->lockForUpdate()->firstOrCreate(
                ['user_id' => $user->id, 'token_id' => $token->id],
                ['quantity' => 0, 'avg_price' => 0, 'realized_pnl' => 0],
            );

            $order->side === OrderSide::Buy
                ? $this->fillBuy($order, $user, $position, $token, $marketPrice)
                : $this->fillSell($order, $user, $position, $token, $marketPrice);

            return $order;
        });
    }

    public function cancel(Order $order): Order
    {
        if ($order->status === OrderStatus::Pending) {
            $order->update(['status' => OrderStatus::Cancelled]);
        }

        return $order;
    }

    /**
     * Fill every pending order whose trigger condition is met at the current price.
     *
     * @param  Collection<int, Token>  $tokens
     */
    public function matchPendingOrders(Collection $tokens): int
    {
        $filled = 0;
        $pricedTokens = $tokens->filter(fn (Token $token): bool => $token->price_usd !== null)->keyBy('id');

        Order::query()
            ->where('status', OrderStatus::Pending)
            ->whereIn('token_id', $pricedTokens->keys())
            ->with('token')
            ->orderBy('id')
            ->each(function (Order $order) use ($pricedTokens, &$filled): void {
                $price = $pricedTokens[$order->token_id]->price_usd;

                if ($order->type->isTriggered($order->side, (float) $order->trigger_price, $price)) {
                    $this->execute($order, $price);
                    $filled++;
                }
            });

        return $filled;
    }

    /**
     * Market buy now, then a stop loss for the whole position and a take profit for a third at each target.
     *
     * @return list<Order>
     *
     * @throws ValidationException
     */
    public function followSignal(User $user, Signal $signal, float $amountUsd): array
    {
        $token = $signal->token;
        $buy = $this->place($user, $token, ['side' => 'buy', 'type' => 'market', 'amount_usd' => $amountUsd, 'note' => "Ikuti sinyal #{$signal->id}"], $signal);

        if ($buy->status !== OrderStatus::Filled) {
            throw ValidationException::withMessages(['amount_usd' => $buy->note ?? 'Order beli gagal.']);
        }

        $orders = [$buy];
        $quantity = $buy->filled_quantity;
        $orders[] = $this->place($user, $token, [
            'side' => 'sell', 'type' => 'stop_loss', 'quantity' => $quantity, 'trigger_price' => $signal->stop_loss, 'note' => "SL sinyal #{$signal->id}",
        ], $signal);

        foreach ($signal->takeProfits() as $index => $target) {
            $orders[] = $this->place($user, $token, [
                'side' => 'sell', 'type' => 'take_profit', 'quantity' => $quantity / 3, 'trigger_price' => $target, 'note' => 'TP'.($index + 1)." sinyal #{$signal->id}",
            ], $signal);
        }

        return $orders;
    }

    /**
     * Wipe positions and orders and restore the starting balance.
     */
    public function reset(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->orders()->delete();
            $user->positions()->delete();
            User::query()->whereKey($user->id)->update(['balance_usd' => config('market.simulator.starting_balance')]);
            $user->refresh();
        });
    }

    /**
     * Price impact for a swap of the given USD size, using the constant product formula
     * against the quote side of the pool (roughly half the reported liquidity).
     */
    public static function priceImpact(float $tradeUsd, ?float $liquidityUsd): float
    {
        if (! $liquidityUsd || $liquidityUsd <= 0) {
            return 0.0;
        }

        $reserve = $liquidityUsd / 2;

        return $tradeUsd / ($reserve + $tradeUsd);
    }

    private function fillBuy(Order $order, User $user, Position $position, Token $token, float $marketPrice): void
    {
        $amount = (float) $order->amount_usd;

        if ($user->balance_usd + self::DUST < $amount) {
            $this->reject($order, 'Saldo USD tidak cukup saat order dieksekusi.');

            return;
        }

        $fee = $amount * config('market.simulator.fee_rate');
        $impact = self::priceImpact($amount - $fee, $token->liquidity_usd);
        $fillPrice = $marketPrice / (1 - $impact);
        $quantity = ($amount - $fee) / $fillPrice;

        $newQuantity = $position->quantity + $quantity;
        $position->update([
            'avg_price' => ($position->quantity * $position->avg_price + $amount) / $newQuantity,
            'quantity' => $newQuantity,
        ]);

        $user->update(['balance_usd' => $user->balance_usd - $amount]);

        $order->update([
            'status' => OrderStatus::Filled,
            'filled_price' => $fillPrice,
            'filled_quantity' => $quantity,
            'total_usd' => $amount,
            'fee_usd' => $fee,
            'slippage_pct' => $impact * 100,
            'filled_at' => now(),
        ]);
    }

    private function fillSell(Order $order, User $user, Position $position, Token $token, float $marketPrice): void
    {
        $quantity = min((float) $order->quantity, $position->quantity);

        if ($quantity <= self::DUST) {
            $this->reject($order, 'Posisi sudah kosong.');

            return;
        }

        $gross = $quantity * $marketPrice;
        $impact = self::priceImpact($gross, $token->liquidity_usd);
        $fillPrice = $marketPrice * (1 - $impact);
        $proceeds = $quantity * $fillPrice;
        $fee = $proceeds * config('market.simulator.fee_rate');
        $net = $proceeds - $fee;
        $pnl = $net - $quantity * $position->avg_price;

        $remaining = $position->quantity - $quantity;
        $position->update([
            'quantity' => $remaining <= self::DUST ? 0 : $remaining,
            'avg_price' => $remaining <= self::DUST ? 0 : $position->avg_price,
            'realized_pnl' => $position->realized_pnl + $pnl,
        ]);

        $user->update(['balance_usd' => $user->balance_usd + $net]);

        $order->update([
            'status' => OrderStatus::Filled,
            'filled_price' => $fillPrice,
            'filled_quantity' => $quantity,
            'total_usd' => $net,
            'fee_usd' => $fee,
            'slippage_pct' => $impact * 100,
            'realized_pnl' => $pnl,
            'filled_at' => now(),
        ]);

        if ($remaining <= self::DUST) {
            $user->orders()
                ->where('token_id', $token->id)
                ->where('side', OrderSide::Sell)
                ->where('status', OrderStatus::Pending)
                ->update(['status' => OrderStatus::Cancelled, 'note' => 'Dibatalkan otomatis: posisi sudah ditutup.']);
        }
    }

    private function reject(Order $order, string $reason): void
    {
        $order->update(['status' => OrderStatus::Rejected, 'note' => $reason]);
    }

    private function positionQuantity(User $user, Token $token): float
    {
        return (float) $user->positions()->where('token_id', $token->id)->value('quantity');
    }
}
