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
     * A buy may carry a take profit and/or stop loss: once it fills, both are placed for the
     * filled quantity as a one-cancels-the-other pair (like TP/SL on Binance or Bybit).
     *
     * @param  array{side: string, type: string, amount_usd?: float|null, quantity?: float|null, trigger_price?: float|null, take_profit_price?: float|null, stop_loss_price?: float|null, exit_plan?: string|null, note?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function place(User $user, Token $token, array $data, ?Signal $signal = null): Order
    {
        $side = OrderSide::from($data['side']);
        $type = OrderType::from($data['type']);
        $takeProfit = $side === OrderSide::Buy ? ($data['take_profit_price'] ?? null) : null;
        $stopLoss = $side === OrderSide::Buy ? ($data['stop_loss_price'] ?? null) : null;

        if ($takeProfit !== null || $stopLoss !== null) {
            $this->validateExits($token, $type, $data, $takeProfit, $stopLoss);
        }

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
            'take_profit_price' => $takeProfit,
            'stop_loss_price' => $stopLoss,
            'exit_plan' => $side === OrderSide::Buy ? ($data['exit_plan'] ?? null) : null,
            'note' => $data['note'] ?? null,
        ]);

        // A limit buy at or above the current price is marketable: fill it now, like an exchange would.
        if ($type === OrderType::Limit && $token->price_usd !== null && $type->isTriggered($side, (float) $order->trigger_price, $token->price_usd)) {
            $this->execute($order, $token->price_usd);
        }

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

            if ($order->side === OrderSide::Buy) {
                $this->fillBuy($order, $user, $position, $token, $marketPrice);
                $this->placeExits($order);
            } else {
                $this->fillSell($order, $user, $position, $token, $marketPrice);
                $this->afterExitFill($order);
            }

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
     * Buy into a signal (market, or limit at the top of its entry zone). Once the buy fills, the
     * position is managed like the signal tracker does: a stop loss for everything, a third sold at
     * each target, and the stop moved to the entry price after the first target.
     *
     * @return list<Order> The buy first, then any exit orders already placed.
     *
     * @throws ValidationException
     */
    public function followSignal(User $user, Signal $signal, float $amountUsd, bool $limit = false): array
    {
        $token = $signal->token;

        if (! $signal->status->isOpen()) {
            throw ValidationException::withMessages(['signal' => 'Sinyal ini sudah ditutup.']);
        }

        $this->prices->ensureFresh($token);

        if ($token->price_usd !== null && $token->price_usd <= $signal->stop_loss) {
            throw ValidationException::withMessages(['signal' => 'Harga sudah di bawah stop loss sinyal.']);
        }

        if ($user->balance_usd < $amountUsd) {
            throw ValidationException::withMessages(['amount_usd' => 'Saldo USD tidak cukup.']);
        }

        $buy = $this->place($user, $token, [
            'side' => 'buy',
            'type' => $limit ? 'limit' : 'market',
            'amount_usd' => $amountUsd,
            'trigger_price' => $limit ? $signal->entry_high : null,
            'exit_plan' => 'signal',
            'note' => "Ikuti sinyal #{$signal->id}",
        ], $signal);

        if (in_array($buy->status, [OrderStatus::Rejected, OrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['amount_usd' => $buy->note ?? 'Order beli gagal.']);
        }

        return [$buy, ...Order::query()->where('parent_id', $buy->id)->orderBy('id')->get()->all()];
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

    /**
     * The stop loss must sit below and the take profit above the price the buy is expected to fill at.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function validateExits(Token $token, OrderType $type, array $data, ?float $takeProfit, ?float $stopLoss): void
    {
        if ($type === OrderType::Market) {
            $this->prices->ensureFresh($token);
        }

        $reference = $type === OrderType::Market ? $token->price_usd : (float) ($data['trigger_price'] ?? 0);
        $label = $type === OrderType::Market ? 'harga sekarang' : 'harga limit';

        if (! $reference) {
            return;
        }

        if ($stopLoss !== null && $stopLoss >= $reference) {
            throw ValidationException::withMessages(['stop_loss_price' => "Stop loss harus di bawah {$label}."]);
        }

        if ($takeProfit !== null && $takeProfit <= $reference) {
            throw ValidationException::withMessages(['take_profit_price' => "Take profit harus di atas {$label}."]);
        }
    }

    /**
     * After a buy with TP/SL fills, protect exactly the bought quantity.
     */
    private function placeExits(Order $order): void
    {
        if ($order->status !== OrderStatus::Filled) {
            return;
        }

        if ($order->exit_plan === 'signal' && $order->signal !== null) {
            $this->placeSignalExits($order, $order->signal);

            return;
        }

        if ($order->take_profit_price === null && $order->stop_loss_price === null) {
            return;
        }

        $exits = array_filter([
            OrderType::TakeProfit->value => $order->take_profit_price,
            OrderType::StopLoss->value => $order->stop_loss_price,
        ]);

        foreach ($exits as $type => $price) {
            Order::create([
                'user_id' => $order->user_id,
                'token_id' => $order->token_id,
                'signal_id' => $order->signal_id,
                'parent_id' => $order->id,
                'side' => OrderSide::Sell,
                'type' => $type,
                'status' => OrderStatus::Pending,
                'quantity' => $order->filled_quantity,
                'trigger_price' => $price,
                'note' => ($type === OrderType::StopLoss->value ? 'SL' : 'TP')." untuk order beli #{$order->id}",
            ]);
        }
    }

    /**
     * Stop loss for the whole filled quantity and a third of it at each of the signal's targets.
     */
    private function placeSignalExits(Order $order, Signal $signal): void
    {
        $exits = [[OrderType::StopLoss, $signal->stop_loss, $order->filled_quantity, "SL sinyal #{$signal->id} (order #{$order->id})"]];

        foreach ($signal->takeProfits() as $index => $target) {
            $exits[] = [OrderType::TakeProfit, $target, $order->filled_quantity / 3, 'TP'.($index + 1)." sinyal #{$signal->id} (order #{$order->id})"];
        }

        foreach ($exits as [$type, $price, $quantity, $note]) {
            Order::create([
                'user_id' => $order->user_id,
                'token_id' => $order->token_id,
                'signal_id' => $signal->id,
                'parent_id' => $order->id,
                'side' => OrderSide::Sell,
                'type' => $type,
                'status' => OrderStatus::Pending,
                'quantity' => $quantity,
                'trigger_price' => $price,
                'note' => $note,
            ]);
        }
    }

    /**
     * After an exit of a buy fills. A plain TP/SL pair is one-cancels-the-other. A signal plan keeps
     * its remaining targets and moves the stop to the entry price for the quantity still held.
     */
    private function afterExitFill(Order $order): void
    {
        if ($order->parent_id === null || $order->status !== OrderStatus::Filled) {
            return;
        }

        $parent = Order::query()->find($order->parent_id);
        $pending = fn () => Order::query()->where('parent_id', $order->parent_id)->whereKeyNot($order->id)->where('status', OrderStatus::Pending);

        if ($parent?->exit_plan === 'signal' && $order->type === OrderType::TakeProfit) {
            $remaining = (float) $pending()->where('type', OrderType::TakeProfit)->sum('quantity');
            $stop = $pending()->where('type', OrderType::StopLoss)->first();

            if ($remaining <= self::DUST) {
                $stop?->update(['status' => OrderStatus::Cancelled, 'note' => 'Dibatalkan otomatis: semua target tercapai.']);
            } else {
                $stop?->update([
                    'trigger_price' => max((float) $stop->trigger_price, (float) $parent->filled_price),
                    'quantity' => $remaining,
                    'note' => "SL dipindah ke harga entry (breakeven) setelah #{$order->id} terisi.",
                ]);
            }

            return;
        }

        $pending()->update(['status' => OrderStatus::Cancelled, 'note' => "Dibatalkan otomatis (OCO): pasangannya #{$order->id} sudah terisi."]);
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
