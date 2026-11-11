<?php

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Models\Analysis;
use App\Models\Signal;
use App\Models\Token;
use App\Models\TokenSecurityCheck;
use App\Models\User;
use App\Services\Analysis\MarketContext;
use App\Services\Analysis\SignalEngine;
use App\Services\Analysis\StrategyVersion;
use App\Services\Analysis\TechnicalAnalyzer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['api.alternative.me/*' => Http::response(['data' => [['value' => '60', 'value_classification' => 'Greed', 'timestamp' => '0']]])]);
    Http::preventStrayRequests();
    // Most tests use a tight fake stop; the minimum stop rule has its own test.
    config(['market.analysis.min_stop_pct' => 0.0]);
});

function fakeTechnical(int $score, bool $available = true): void
{
    test()->mock(TechnicalAnalyzer::class, fn ($mock) => $mock->shouldReceive('analyze')->andReturn([
        'score' => $score,
        'available' => $available,
        'reasons' => ['test'],
        'indicators' => ['rsi' => 60.0],
        'atr' => 0.02,
        'swing_low' => 0.97,
        'swing_high' => 1.1,
    ]));
}

test('strong scores produce a buy with ordered levels and a tracked signal', function () {
    fakeTechnical(95);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation->isBullish())->toBeTrue()
        ->and($analysis->stop_loss)->toBeLessThan($analysis->entry_low)
        ->and($analysis->entry_high)->toBeLessThan($analysis->take_profit_1)
        ->and($analysis->take_profit_1)->toBeLessThan($analysis->take_profit_2)
        ->and($analysis->take_profit_2)->toBeLessThan($analysis->take_profit_3)
        ->and($analysis->summary)->not->toBeEmpty()
        ->and($token->signals()->first()->status)->toBe(SignalStatus::Pending);
});

test('honeypot tokens are always avoided', function () {
    fakeTechnical(95);
    $token = Token::factory()->create();
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 0, 'is_honeypot' => true]);

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation)->toBe(Recommendation::Avoid)
        ->and($analysis->entry_low)->toBeNull()
        ->and($token->signals()->count())->toBe(0);
});

test('bullish score without candle data falls back to hold', function () {
    fakeTechnical(50, available: false);
    $token = Token::factory()->create();
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    expect(app(SignalEngine::class)->analyze($token)->recommendation->isBullish())->toBeFalse();
});

test('a second bullish analysis does not open a duplicate signal', function () {
    fakeTechnical(95);
    $token = Token::factory()->create();
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    app(SignalEngine::class)->analyze($token);
    app(SignalEngine::class)->analyze($token);

    expect($token->analyses()->count())->toBe(2)
        ->and($token->signals()->count())->toBe(1);
});

test('analyses and signals record the strategy version and the market around them', function () {
    Token::factory()->create(['chain_id' => 'solana', 'symbol' => 'SOL', 'price_change_h24' => -2.5]);
    Token::factory()->create(['price_change_h24' => 4.0]);

    $context = app(MarketContext::class)->snapshot();

    expect($context['fear_greed']['value'])->toBe(60)
        ->and($context['benchmarks']['SOL']['change_24h'])->toEqual(-2.5)
        ->and($context['benchmarks']['ETH'])->toBe(['change_24h' => null, 'trend_4h' => null])
        ->and($context['breadth'])->toMatchArray(['tokens' => 2, 'up_24h_pct' => 50.0]);

    $version = StrategyVersion::current();
    expect($version)->toStartWith('v5-');

    config(['market.analysis.weights.technical' => 0.4]);
    expect(StrategyVersion::current())->not->toBe($version);
});

test('a buy with a stop too close becomes hold without a signal, and the rule is part of the strategy version', function () {
    fakeTechnical(95);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 90]);
    $version = StrategyVersion::current();

    config(['market.analysis.min_stop_pct' => 50.0]);

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation)->toBe(Recommendation::Hold)
        ->and($analysis->stop_loss)->toBeNull()
        ->and($analysis->reasons[0])->toContain('jarak stop')
        ->and($token->signals()->count())->toBe(0)
        ->and(StrategyVersion::current())->not->toBe($version);
});

/**
 * Technical analysis with a given day move, scoring high enough for a bullish recommendation.
 */
function fakeTechnicalMove(?float $rise, ?float $belowHigh): void
{
    test()->mock(TechnicalAnalyzer::class, fn ($mock) => $mock->shouldReceive('analyze')->andReturn([
        'score' => 95,
        'available' => true,
        'reasons' => ['test'],
        'indicators' => ['rsi' => 60.0, 'rise_24h_pct' => $rise, 'below_high_24h_pct' => $belowHigh],
        'atr' => 0.02,
        'swing_low' => 0.97,
        'swing_high' => 1.1,
    ]));
}

test('v4 holds a Buy that chases the move, with the reason, and opens no signal', function (?float $rise, ?float $belowHigh, string $reason) {
    config(['market.analysis.thresholds.STRONG_BUY' => 101, 'market.analysis.buy_max_rise_24h_pct' => 15.0]);
    fakeTechnicalMove($rise, $belowHigh);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation)->toBe(Recommendation::Hold)
        ->and($analysis->reasons[0])->toContain($reason)
        ->and($analysis->indicators['rise_24h_pct'])->toEqual($rise)
        ->and($token->signals()->count())->toBe(0);
})->with([
    'risen 20% in a day' => [20.0, 12.0, 'naik 20.0% dalam 24 jam'],
    'within 2% of the day high' => [8.0, 2.0, 'di bawah puncak 24 jam'],
]);

test('v4 keeps a Buy that waited for a pullback, and a Buy without a day of history', function (?float $rise, ?float $belowHigh) {
    config(['market.analysis.thresholds.STRONG_BUY' => 101, 'market.analysis.signal_recommendations' => ['BUY', 'STRONG_BUY']]);
    fakeTechnicalMove($rise, $belowHigh);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation)->toBe(Recommendation::Buy)
        ->and($token->signals()->count())->toBe(1);
})->with([
    'calm day, below the high' => [10.0, 8.0],
    'exactly at the limits' => [15.0, 5.0],
    'no history yet' => [null, null],
]);

test('v4 leaves a Strong Buy alone as the comparison', function () {
    config(['market.analysis.thresholds.STRONG_BUY' => 1]);
    fakeTechnicalMove(40.0, 0.5);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation)->toBe(Recommendation::StrongBuy)
        ->and($token->signals()->count())->toBe(1);
});

test('v4 holds a Buy after a big rise by default, even well below the day high', function () {
    config(['market.analysis.thresholds.STRONG_BUY' => 101]);
    fakeTechnicalMove(40.0, 12.0);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    expect(app(SignalEngine::class)->analyze($token)->recommendation)->toBe(Recommendation::Hold);
});

test('a v4 rule set to 0 is off, so the other one can run alone', function () {
    config(['market.analysis.thresholds.STRONG_BUY' => 101, 'market.analysis.buy_min_below_high_24h_pct' => 0.0]);
    fakeTechnicalMove(8.0, 2.0);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    expect(app(SignalEngine::class)->analyze($token)->recommendation)->toBe(Recommendation::Buy);

    config(['market.analysis.buy_max_rise_24h_pct' => 0.0]);
    fakeTechnicalMove(40.0, 12.0);
    $other = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($other)->create(['risk_score' => 100]);

    expect(app(SignalEngine::class)->analyze($other)->recommendation)->toBe(Recommendation::Buy);
});

test('the v4 rules can be set from the admin panel, and turning one off makes a new strategy version', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $before = StrategyVersion::current();

    $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.analysis.buy_min_below_high_24h_pct' => 0]])->assertOk();

    expect(config('market.analysis.buy_min_below_high_24h_pct'))->toEqual(0.0)
        ->and(config('market.analysis.buy_max_rise_24h_pct'))->toEqual(15.0)
        ->and(StrategyVersion::current())->toStartWith('v5-')->not->toBe($before);

    $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.analysis.buy_max_rise_24h_pct' => -1]])->assertUnprocessable();
});

test('the v4 limits are part of the strategy version', function () {
    $version = StrategyVersion::current();

    expect($version)->toStartWith('v5-');

    config(['market.analysis.buy_max_rise_24h_pct' => 20.0]);

    expect(StrategyVersion::current())->not->toBe($version);
});

test('the day move compares the price with 24 candles ago and with their highest high', function () {
    $closes = array_map(fn (int $i): float => 1.0 + $i * 0.01, range(0, 29));
    $highs = array_map(fn (float $close): float => $close + 0.05, $closes);

    $move = TechnicalAnalyzer::dayMove($closes, $highs, 1.29);

    // 24 candles back is close #5 (1.05); the highest of the last 24 highs is 1.34.
    expect(round($move['rise_24h_pct'], 2))->toBe(round((1.29 / 1.05 - 1) * 100, 2))
        ->and(round($move['below_high_24h_pct'], 2))->toBe(round((1 - 1.29 / 1.34) * 100, 2))
        ->and(TechnicalAnalyzer::dayMove(array_slice($closes, 0, 10), array_slice($highs, 0, 10), 1.0)['rise_24h_pct'])->toBeNull();
});

test('the first analysis that is no longer bullish is noted on an active signal, without closing it', function () {
    fakeTechnical(10);
    $token = Token::factory()->create(['price_usd' => 1.07]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);
    $active = Signal::withoutEvents(fn () => Signal::factory()->for($token)->create(['status' => 'active', 'max_tp_hit' => 1, 'entry_price' => 1.0, 'entered_at' => now()->subHour()]));
    $waiting = Signal::withoutEvents(fn () => Signal::factory()->for(Token::factory()->create())->create(['status' => 'pending']));

    $analysis = app(SignalEngine::class)->analyze($token);
    $active->refresh();

    expect($analysis->recommendation->isBullish())->toBeFalse()
        ->and($active->status)->toBe(SignalStatus::Active)
        ->and($active->downgraded_at->toDateTimeString())->toBe($analysis->created_at->toDateTimeString())
        ->and($active->downgrade_price)->toEqual(1.07)
        ->and($active->downgrade_recommendation)->toBe($analysis->recommendation->value)
        ->and($active->downgrade_tp_hit)->toBe(1)
        ->and($waiting->refresh()->downgraded_at)->toBeNull();

    // The first one stays; the second in a row is noted on its own.
    $this->travel(15)->minutes();
    $token->update(['price_usd' => 0.9]);
    app(SignalEngine::class)->analyze($token);
    $active->refresh();

    expect($active->downgrade_price)->toEqual(1.07)
        ->and($active->second_downgrade_price)->toEqual(0.9)
        ->and($active->second_downgrade_tp_hit)->toBe(1);
});

test('a lone Hold between Buys is not a second downgrade, and a Hold from before entry does not count', function () {
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);
    // A Hold before the signal was entered.
    Analysis::factory()->for($token)->create(['recommendation' => 'HOLD', 'created_at' => now()->subHours(2)]);
    $active = Signal::withoutEvents(fn () => Signal::factory()->for($token)->create(['status' => 'active', 'entry_price' => 1.0, 'entered_at' => now()->subHour()]));

    fakeTechnical(10);
    app(SignalEngine::class)->analyze($token);

    expect($active->refresh()->downgraded_at)->not->toBeNull()
        ->and($active->second_downgraded_at)->toBeNull();
});

test('v5 opens signals only for Strong Buy, and a Buy stays a watch-only analysis with its levels', function () {
    config(['market.analysis.thresholds.STRONG_BUY' => 101]);
    fakeTechnicalMove(10.0, 8.0);
    $buy = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($buy)->create(['risk_score' => 100]);

    $analysis = app(SignalEngine::class)->analyze($buy);

    expect($analysis->recommendation)->toBe(Recommendation::Buy)
        ->and($analysis->entry_low)->not->toBeNull()
        ->and($buy->signals()->count())->toBe(0);

    config(['market.analysis.thresholds.STRONG_BUY' => 72]);
    $strong = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($strong)->create(['risk_score' => 100]);

    expect(app(SignalEngine::class)->analyze($strong)->recommendation)->toBe(Recommendation::StrongBuy)
        ->and($strong->signals()->count())->toBe(1);
});

test('the recommendations that open signals are part of the strategy version', function () {
    $version = StrategyVersion::current();

    config(['market.analysis.signal_recommendations' => ['BUY', 'STRONG_BUY']]);

    expect(StrategyVersion::current())->not->toBe($version);
});
