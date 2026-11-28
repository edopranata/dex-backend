<?php

namespace App\Services\Strategies;

use App\Models\Candle;
use App\Models\StrategyAccount;
use App\Models\StrategyPosition;
use App\Models\StrategySnapshot;
use App\Models\Token;
use App\Services\MarketData\BenchmarkTokens;
use App\Services\Strategies\Rules\MartingaleLong;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One pass over the strategy accounts (config market.strategy_accounts), every 15 minutes after the candle cycle.
 *
 * Positions: each new closed 15m candle is checked for a limit fill (or its expiry, or the target trading first), the
 * stop (filled at the stop, or the open when the candle opened below it), the target, and martingale adds; then the time
 * limit, and the rule's own exit once the token's candle of the last closed hour is stored.
 *
 * Entries: once per token and closed hour, when the token's 1h candle of that hour is stored (and for a 4h close its 4h
 * candle), the token's liquidity is at least min_liquidity_usd, and the account has room. A token is decided at most an
 * hour late: candles of tokens outside the Market arrive in turns, and a stale one is left out rather than entered late.
 * Fills are at the token's live price while fresh, else the last 15m close.
 */
class StrategyRunner
{
    public function __construct(private StrategyBroker $broker, private BenchmarkTokens $benchmarks) {}

    public function ensureAccounts(): void
    {
        foreach ((array) config('market.strategy_accounts.strategies') as $key => $definition) {
            StrategyAccount::query()->firstOrCreate(['key' => $key], [
                'label' => $definition['label'],
                'kind' => $definition['kind'],
                'starting_balance' => (float) config('market.strategy_accounts.starting_balance'),
                'cash' => (float) config('market.strategy_accounts.starting_balance'),
                'started_at' => now(),
            ]);
        }
    }

    /**
     * @return array{exits: int, entries: int, decided: int}
     */
    public function run(?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $this->ensureAccounts();
        $hourEnd = intdiv($now, 3600) * 3600;
        $context = new StrategyContext($now, $hourEnd, $this->benchmarks);
        $accounts = StrategyAccount::query()->where('is_active', true)->orderBy('id')->get()
            ->filter(fn (StrategyAccount $account): bool => config("market.strategy_accounts.strategies.{$account->key}") !== null)
            ->keyBy('id');
        $strategies = $accounts->map(fn (StrategyAccount $account): Strategy => app(config("market.strategy_accounts.strategies.{$account->key}.class")));
        $result = ['exits' => 0, 'entries' => 0, 'decided' => 0];

        $positions = StrategyPosition::query()->whereIn('strategy_account_id', $accounts->keys())
            ->whereIn('status', [StrategyPosition::PENDING, StrategyPosition::OPEN])->with(['token', 'account'])->get();

        foreach ($positions as $position) {
            try {
                $result['exits'] += $this->follow($position, $strategies[$position->strategy_account_id], $context);
            } catch (Throwable $e) {
                report($e);
            }
            $context->forget($position->token_id);
        }

        // Token by token, so the candles one token loads serve every account deciding on it.
        $eligible = $this->eligibleTokens($hourEnd);
        $plan = [];
        foreach ($accounts as $id => $account) {
            if (! $this->due($strategies[$id], $context)) {
                continue;
            }
            foreach ($strategies[$id]->tokens($eligible, $context) as $token) {
                $plan[$token->id]['token'] = $token;
                $plan[$token->id]['accounts'][] = $id;
            }
        }

        foreach ($plan as $tokenId => ['token' => $token, 'accounts' => $ids]) {
            foreach ($ids as $id) {
                $account = $accounts[$id];
                $key = "strategy-accounts:{$account->key}:{$tokenId}";
                if ((int) Cache::get($key, 0) >= $hourEnd || ! $this->ready($token, $hourEnd, $context, $strategies[$id])) {
                    continue;
                }
                Cache::put($key, $hourEnd, now()->addDays(2));
                $result['decided']++;

                try {
                    $result['entries'] += $this->enter($account->refresh(), $strategies[$id], $token, $context);
                } catch (Throwable $e) {
                    report($e);
                }
            }
            $context->forget($tokenId);
        }

        return $result;
    }

    /**
     * Hourly equity of every account, for the drawdown.
     */
    public function snapshot(): int
    {
        $count = 0;
        foreach (StrategyAccount::query()->where('is_active', true)->get() as $account) {
            StrategySnapshot::query()->create(['strategy_account_id' => $account->id, 'equity' => $this->broker->equity($account), 'cash' => $account->cash, 'taken_at' => now()]);
            $count++;
        }

        return $count;
    }

    /**
     * @return int 1 when the position was (partly) sold, else 0.
     */
    private function follow(StrategyPosition $position, Strategy $strategy, StrategyContext $context): int
    {
        $candles = Candle::query()->where('token_id', $position->token_id)->where('timeframe', '15m')
            ->where('open_time', '>', $position->checked_until)->where('open_time', '<=', $context->now - 900)
            ->orderBy('open_time')->get(['open_time', 'open', 'high', 'low', 'close']);

        foreach ($candles as $candle) {
            $position->checked_until = (int) $candle->open_time;
            $end = (int) $candle->open_time + 900;

            if ($position->status === StrategyPosition::PENDING) {
                $state = $position->state;
                if (isset($state['expires_at']) && $candle->open_time >= $state['expires_at']) {
                    return $this->cancel($position, 'Order limit kedaluwarsa', (int) $candle->open_time);
                }
                if ($candle->low > $state['limit']) {
                    if (isset($state['cancel_price']) && $candle->high >= $state['cancel_price']) {
                        return $this->cancel($position, 'Target tersentuh sebelum order limit terisi', $end);
                    }

                    continue;
                }
                if (! $this->broker->fillLimit($position, min((float) $state['limit'], (float) $candle->open), (int) $candle->open_time)) {
                    return 0;
                }
                $position->checked_until = (int) $candle->open_time;
            }

            if ($position->stop !== null && $candle->low <= $position->stop) {
                $moved = $position->stop >= $position->entry_price - 1e-12 * $position->entry_price;
                $this->broker->close($position, 1.0, min((float) $position->stop, (float) $candle->open), $moved ? 'Stop (di harga masuk)' : 'Stop', $end);

                return 1;
            }

            if (($position->state['martingale'] ?? false) && ($add = MartingaleLong::nextAdd($position)) !== null && $candle->low <= $add['price']) {
                // Every add level the candle reached, in order; the new target counts from the next candle.
                while ($add !== null && $candle->low <= $add['price']) {
                    if (! $this->broker->add($position, min($add['price'], (float) $candle->open), $add['notional'])) {
                        break;
                    }
                    MartingaleLong::afterAdd($position);
                    $add = MartingaleLong::nextAdd($position);
                }

                continue;
            }

            if ($position->take_profit !== null && $candle->high >= $position->take_profit) {
                $this->broker->close($position, 1.0, max((float) $position->take_profit, (float) $candle->open), 'Target', $end);

                return 1;
            }
        }

        if ($position->status === StrategyPosition::PENDING) {
            $position->save();

            return 0;
        }

        $price = $this->broker->lastPrice($position->token, $position->entry_price);

        if ($strategy->maxHours() > 0 && $context->now - $position->opened_at->getTimestamp() >= $strategy->maxHours() * 3600) {
            $this->broker->close($position, 1.0, $price, 'Batas waktu');

            return 1;
        }

        $exit = null;
        // The rule's own exit once per closed hour: when the token's candles of that hour are in, or 45 minutes after it
        // for a token without trades that hour (re-reading the last stored candle gives the same answer as before).
        $managed = (int) ($position->state['managed_until'] ?? 0) >= $context->hourEnd;
        if (! $managed && ($this->ready($position->token, $context->hourEnd, $context, $strategy) || $context->now >= $context->hourEnd + 2700)) {
            $exit = $strategy->manage($position, $context);
            $position->state = [...$position->state, 'managed_until' => $context->hourEnd];
        }
        $position->save();

        if ($exit !== null) {
            $this->broker->close($position, $exit['fraction'], $price, $exit['reason']);

            return 1;
        }

        return 0;
    }

    private function cancel(StrategyPosition $position, string $reason, int $at): int
    {
        $position->update(['status' => StrategyPosition::CANCELLED, 'closed_at' => now()->setTimestamp($at), 'exit_reason' => $reason, 'checked_until' => $position->checked_until]);

        return 0;
    }

    private function enter(StrategyAccount $account, Strategy $strategy, Token $token, StrategyContext $context): int
    {
        $holding = StrategyPosition::query()->where('strategy_account_id', $account->id)
            ->whereIn('status', [StrategyPosition::PENDING, StrategyPosition::OPEN]);
        if ((clone $holding)->where('token_id', $token->id)->exists() || (clone $holding)->count() >= (int) config('market.strategy_accounts.max_positions')) {
            return 0;
        }

        $entry = $strategy->entry($token, $context);
        if ($entry === null) {
            return 0;
        }

        $onceKey = $entry['state']['once_key'] ?? null;
        if ($onceKey !== null && StrategyPosition::query()->where('strategy_account_id', $account->id)->where('token_id', $token->id)->where('state->once_key', $onceKey)->exists()) {
            return 0;
        }

        $price = $this->broker->lastPrice($token, 0.0);
        $lastQuarter = intdiv($context->now, 900) * 900 - 900;

        return $this->broker->open($account, $token, $entry, $price, $this->broker->equity($account), $lastQuarter) === null ? 0 : 1;
    }

    /**
     * Whether the rule decides at this hour: every hour for '1h' rules, at the UTC day's close for '1d' ones.
     */
    private function due(Strategy $strategy, StrategyContext $context): bool
    {
        return $strategy->timeframe() === '1h' || $context->dayClosed();
    }

    /**
     * Whether the token's candles of the hour that just closed are stored: its 1h candle, and its 4h (or 1d) candle when
     * that hour also closed one.
     */
    private function ready(Token $token, int $hourEnd, StrategyContext $context, Strategy $strategy): bool
    {
        $needed = ['1h' => $hourEnd - 3600];
        if ($context->fourHourClosed()) {
            $needed['4h'] = $hourEnd - 14400;
        }
        if ($strategy->timeframe() === '1d') {
            $needed = $context->dayClosed() ? ['1d' => $hourEnd - 86400] : $needed;
        }

        foreach ($needed as $timeframe => $openTime) {
            if (! Candle::query()->where('token_id', $token->id)->where('timeframe', $timeframe)->where('open_time', $openTime)->exists()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tokens with a 1h candle of the hour that just closed and at least min_liquidity_usd of liquidity; benchmarks are
     * left out (only the trend and benchmark accounts trade them).
     *
     * @return list<Token>
     */
    private function eligibleTokens(int $hourEnd): array
    {
        $fresh = Candle::query()->where('timeframe', '1h')->where('open_time', $hourEnd - 3600)->pluck('token_id');
        $benchmarks = collect($this->benchmarks->names())->map(fn (string $name): ?int => $this->benchmarks->find($name)?->id)->filter();

        /** @var Collection<int, Token> $tokens */
        $tokens = Token::query()->whereIn('id', $fresh)->whereNotIn('id', $benchmarks)
            ->where('liquidity_usd', '>=', (float) config('market.strategy_accounts.min_liquidity_usd'))
            ->orderBy('id')->get();

        return $tokens->all();
    }
}
