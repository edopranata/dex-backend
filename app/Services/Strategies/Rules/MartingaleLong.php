<?php

namespace App\Services\Strategies\Rules;

use App\Models\StrategyPosition;
use App\Models\Token;
use App\Services\Strategies\BaseStrategy;
use App\Services\Strategies\StrategyContext;

/**
 * FutureSim's martingale_long on DEX tokens (config market.strategy_accounts.martingale): a round opens at the price,
 * adds every step_pct below the first price with each add multiplier times the one before, up to `adds`, and sells
 * everything at take_profit_pct above the average; the next round opens at the next hour. No stop. A round's orders
 * together are 1/tokens of equity. DEX tokens have no fixed contract list, so a new round goes to the most liquid
 * eligible tokens, at most `tokens` rounds at a time. The runner places the adds (nextAdd) on 15m candles.
 */
class MartingaleLong extends BaseStrategy
{
    public function maxHours(): int
    {
        return 0;
    }

    public function tokens(array $eligible, StrategyContext $context): array
    {
        usort($eligible, fn (Token $a, Token $b): int => $b->liquidity_usd <=> $a->liquidity_usd);

        return array_slice($eligible, 0, (int) config('market.strategy_accounts.martingale.tokens'));
    }

    public function entry(Token $token, StrategyContext $context): ?array
    {
        $config = config('market.strategy_accounts.martingale');

        return [
            'reason' => 'Martingale: order pertama',
            'take_profit_pct' => (float) $config['take_profit_pct'],
            // The first order is the smallest of the round: equity / tokens / (1 + m + m^2 + ... + m^adds).
            'share' => 1 / ((int) $config['tokens'] * self::sizeSum()),
            'state' => ['adds_done' => 0, 'martingale' => true],
        ];
    }

    /**
     * The next add's price and size, or null when all adds are done.
     *
     * @return array{price: float, notional: float, reason: string}|null
     */
    public static function nextAdd(StrategyPosition $position): ?array
    {
        $config = config('market.strategy_accounts.martingale');
        $state = $position->state;
        $state['first'] ??= $position->entry_price;
        $state['unit'] ??= $position->quantity * $position->entry_price;
        $position->state = $state;
        $done = (int) ($state['adds_done'] ?? 0);

        if ($done >= (int) $config['adds']) {
            return null;
        }

        $k = $done + 1;

        return [
            'price' => $state['first'] * (1 - (float) $config['step_pct'] * $k),
            'notional' => $state['unit'] * (float) $config['multiplier'] ** $k,
            'reason' => "Martingale: tambah ke-{$k}",
        ];
    }

    /**
     * After an add: one more done, and the new target above the average.
     */
    public static function afterAdd(StrategyPosition $position): void
    {
        $state = $position->state;
        $state['adds_done'] = (int) ($state['adds_done'] ?? 0) + 1;
        $position->state = $state;
        $position->take_profit = $position->entry_price * (1 + (float) config('market.strategy_accounts.martingale.take_profit_pct'));
        $position->save();
    }

    private static function sizeSum(): float
    {
        $multiplier = (float) config('market.strategy_accounts.martingale.multiplier');

        return array_sum(array_map(fn (int $k): float => $multiplier ** $k, range(0, (int) config('market.strategy_accounts.martingale.adds'))));
    }
}
