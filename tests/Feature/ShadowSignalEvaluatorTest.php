<?php

use App\Models\Candle;
use App\Models\ShadowSignal;
use App\Models\Token;
use App\Services\Analysis\PerformanceReport;
use App\Services\Analysis\ShadowSignalEvaluator;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-12 12:00:00');
    $this->token = Token::factory()->create();
});

/**
 * A held Strong Buy made at $at: entry zone 0.98-1.00, stop 0.90, targets 1.15 / 1.25 / 1.40.
 */
function shadowAt(Token $token, string $at, string $rule = 'top10_holders'): ShadowSignal
{
    return ShadowSignal::query()->forceCreate([
        'token_id' => $token->id, 'analysis_id' => 1, 'rule' => $rule, 'recommendation' => 'STRONG_BUY', 'strategy_version' => 'v6-test',
        'entry_low' => 0.98, 'entry_high' => 1.00, 'stop_loss' => 0.90, 'take_profit_1' => 1.15, 'take_profit_2' => 1.25, 'take_profit_3' => 1.40,
        'liquidity_usd' => 500_000, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

/**
 * 15m candles from $from, each [open, high, low, close].
 *
 * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $bars
 */
function candles15(Token $token, string $from, array $bars): void
{
    $time = Carbon::parse($from)->timestamp;

    foreach ($bars as [$open, $high, $low, $close]) {
        Candle::factory()->for($token)->create(['timeframe' => '15m', 'open_time' => $time, 'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close]);
        $time += 900;
    }
}

test('a shadow that enters and hits its stop is settled at the stop with costs', function () {
    $shadow = shadowAt($this->token, '2026-11-12 08:05:00');
    candles15($this->token, '2026-11-12 08:15:00', [[1.02, 1.03, 1.01, 1.02], [1.01, 1.01, 0.99, 1.00], [0.99, 0.99, 0.89, 0.90]]);

    expect(app(ShadowSignalEvaluator::class)->run())->toBe(['evaluated' => 1, 'still_open' => 0]);

    $shadow->refresh();
    expect($shadow->status)->toBe('stop_loss')
        ->and($shadow->entry_price)->toBe(1.0)
        ->and($shadow->exit_price)->toBe(0.9)
        ->and($shadow->net_r)->toBeLessThan(-1.0)
        ->and($shadow->entered_at->format('H:i'))->toBe('08:30');
});

test('targets are taken a third at a time and the stop moves to the entry after the first', function () {
    $shadow = shadowAt($this->token, '2026-11-12 08:05:00');
    candles15($this->token, '2026-11-12 08:15:00', [[1.00, 1.05, 0.99, 1.04], [1.04, 1.16, 1.03, 1.10], [1.10, 1.11, 0.99, 1.00]]);

    app(ShadowSignalEvaluator::class)->run();

    $shadow->refresh();
    expect($shadow->status)->toBe('take_profit')
        ->and($shadow->max_tp_hit)->toBe(1)
        ->and($shadow->exit_price)->toBe(1.0)
        ->and($shadow->net_r)->toBeGreaterThan(0.0);
});

test('a shadow never reaching its zone is not entered, and one whose outcome is unknown stays open', function () {
    $missed = shadowAt($this->token, '2026-11-11 08:05:00');
    candles15($this->token, '2026-11-11 08:15:00', array_fill(0, 50, [1.10, 1.12, 1.05, 1.10]));
    $other = Token::factory()->create();
    $running = shadowAt($other, '2026-11-12 11:00:00');
    candles15($other, '2026-11-12 11:15:00', [[1.00, 1.05, 0.99, 1.04]]);

    expect(app(ShadowSignalEvaluator::class)->run())->toBe(['evaluated' => 1, 'still_open' => 1])
        ->and($missed->refresh()->status)->toBe('not_entered')
        ->and($missed->net_r)->toBeNull()
        ->and($running->refresh()->status)->toBe('pending');
});

test('a later shadow on the same token while the first is open counts as overlap, but not across rules', function () {
    $first = shadowAt($this->token, '2026-11-12 08:05:00');
    $second = shadowAt($this->token, '2026-11-12 08:20:00');
    $reference = shadowAt($this->token, '2026-11-12 08:20:00', 'accepted');
    candles15($this->token, '2026-11-12 08:15:00', [[1.00, 1.05, 0.99, 1.04], [1.04, 1.05, 0.89, 0.90]]);

    app(ShadowSignalEvaluator::class)->run();

    expect($first->refresh()->status)->toBe('stop_loss')
        ->and($second->refresh()->status)->toBe('overlap')
        ->and($reference->refresh()->status)->toBe('stop_loss');
});

test('the report compares accepted and held shadows', function () {
    ShadowSignal::query()->forceCreate([...shadowAt($this->token, '2026-11-12 08:05:00')->only(['token_id', 'analysis_id', 'recommendation', 'strategy_version', 'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2', 'take_profit_3']), 'rule' => 'accepted', 'status' => 'take_profit', 'net_r' => 1.5]);
    ShadowSignal::query()->where('rule', 'top10_holders')->update(['status' => 'stop_loss', 'net_r' => -1.1]);

    $rows = collect(app(PerformanceReport::class)->build(null)['holder_filter'])->keyBy('rule');

    expect($rows['accepted'])->toMatchArray(['trades' => 1, 'wins' => 1, 'total_r' => 1.5])
        ->and($rows['top10_holders'])->toMatchArray(['recorded' => 1, 'trades' => 1, 'wins' => 0, 'avg_r' => -1.1]);
});
