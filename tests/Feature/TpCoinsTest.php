<?php

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Models\Analysis;
use App\Models\Candle;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $attributes
 */
function tpSignal(Token $token, array $attributes, array $indicators = []): Signal
{
    $analysis = Analysis::factory()->create([
        'token_id' => $token->id, 'final_score' => 74,
        'indicators' => $indicators + ['rsi' => 61.6, 'volume_ratio' => 1.8, 'rise_24h_pct' => 4.4, 'ema50' => 0.9,
            'fundamental' => ['age_days' => 181.7, 'liquidity_usd' => 51186, 'turnover' => 1.2, 'buy_ratio' => 0.55],
            'btc' => ['corr' => 0.03, 'btc_change_24h' => -0.98]],
        'market_context' => ['benchmarks' => ['BTC' => ['trend_4h' => 'bearish', 'change_24h' => -0.17]], 'fear_greed' => ['value' => 11], 'breadth' => ['up_24h_pct' => 52.6]],
        'reasons' => ['[Teknikal] Harga di atas EMA50.', '[On-chain] Holder tumbuh.'],
    ]);

    return Signal::factory()->create($attributes + [
        'token_id' => $token->id, 'analysis_id' => $analysis->id, 'recommendation' => Recommendation::StrongBuy,
        'entry_price' => 1.0, 'stop_loss' => 0.95, 'take_profit_1' => 1.06, 'entered_at' => '2026-11-18 05:45:00',
        'strategy_version' => 'v6-test',
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 00:00:00', 'UTC'));
    $this->up = Token::factory()->create(['symbol' => 'UP']);
    $this->aria = Token::factory()->create(['symbol' => 'ARIA']);
    $at = fn (string $time): int => CarbonImmutable::parse($time, 'UTC')->getTimestamp();
    // 1h candle before entry: a solid green one. 15m candles: a dip to 0.97, then TP1 in the 07:00 candle.
    Candle::factory()->for($this->up)->create(['timeframe' => '1h', 'open_time' => $at('2026-11-18 04:00'), 'open' => 0.95, 'high' => 1.0, 'low' => 0.95, 'close' => 0.99, 'volume' => 10]);
    Candle::factory()->for($this->up)->create(['timeframe' => '15m', 'open_time' => $at('2026-11-18 05:45'), 'open' => 1.0, 'high' => 1.01, 'low' => 0.97, 'close' => 0.98, 'volume' => 1]);
    Candle::factory()->for($this->up)->create(['timeframe' => '15m', 'open_time' => $at('2026-11-18 07:00'), 'open' => 1.02, 'high' => 1.07, 'low' => 1.02, 'close' => 1.05, 'volume' => 1]);
    Candle::factory()->for($this->up)->create(['timeframe' => '15m', 'open_time' => $at('2026-11-18 09:00'), 'open' => 1.05, 'high' => 1.09, 'low' => 1.0, 'close' => 1.0, 'volume' => 1]);
});

test('tokens whose signal reached TP1 are listed with the entry analysis, and misses only feed the comparison', function () {
    tpSignal($this->up, ['status' => SignalStatus::TakeProfit, 'max_tp_hit' => 1, 'net_r' => 0.32, 'closed_at' => '2026-11-18 13:50:00']);
    tpSignal($this->up, ['status' => SignalStatus::StopLoss, 'net_r' => -1.06, 'entered_at' => '2026-11-14 03:26:00', 'closed_at' => '2026-11-14 22:54:00']);
    tpSignal($this->aria, ['status' => SignalStatus::StopLoss, 'net_r' => -1.03, 'closed_at' => '2026-11-18 17:00:00']);
    tpSignal($this->aria, ['status' => SignalStatus::Pending, 'entered_at' => null, 'entry_price' => null]);

    $data = $this->actingAs(User::factory()->create())->getJson('/api/reports/tp-coins')->assertOk()->json();

    expect($data['totals'])->toBe(['signals' => 3, 'tp' => 1, 'missed' => 2, 'open' => 0])
        ->and($data['coins'])->toHaveCount(1)
        ->and($data['coins'][0])->toMatchArray(['symbol' => 'UP', 'tp' => 1, 'stop' => 1, 'net_r' => -0.74, 'avg_minutes_to_tp' => 90])
        ->and(collect($data['events'])->pluck('symbol')->unique()->values()->all())->toBe(['UP']);

    $hit = collect($data['events'])->firstWhere('outcome', 'tp');
    expect($hit)->toMatchArray([
        'tp_at' => '2026-11-18T07:15:00+00:00', 'minutes_to_tp' => 90, 'mae_pct' => -3, 'mfe_pct' => 9,
        'stop_pct' => 5, 'tp_pct' => 6, 'tp_r' => 1.2, 'candle_1h' => 'Hijau solid', 'score' => 74,
        'btc_trend_4h' => 'bearish', 'fear_greed' => 11, 'volume_ratio' => 1.8, 'buy_ratio' => 55, 'age_days' => 182,
        'reasons' => ['[Teknikal] Harga di atas EMA50.'],
    ]);

    $score = collect($data['compare'])->firstWhere('key', 'score');
    expect($score['hit'])->toEqual(74)->and($score['missed'])->toEqual(74);
});
