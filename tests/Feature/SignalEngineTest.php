<?php

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Models\Analysis;
use App\Models\Candle;
use App\Models\Order;
use App\Models\OrderFillEvidence;
use App\Models\Position;
use App\Models\ShadowSignal;
use App\Models\Signal;
use App\Models\Token;
use App\Models\TokenSecurityCheck;
use App\Models\User;
use App\Services\Analysis\MarketContext;
use App\Services\Analysis\SignalEngine;
use App\Services\Analysis\StrategyVersion;
use App\Services\Analysis\TechnicalAnalyzer;
use App\Services\MarketData\CheckedPrice;
use App\Services\MarketData\PriceCrossCheck;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['api.alternative.me/*' => Http::response(['data' => [['value' => '60', 'value_classification' => 'Greed', 'timestamp' => '0']]])]);
    Http::preventStrayRequests();
    // Most tests use a tight fake stop and a fake technical score high enough to pass v7's Strong Buy score limit, and
    // analyze a token once, so v8's previous-Buy rule is off; the three rules have their own tests.
    config(['market.analysis.min_stop_pct' => 0.0, 'market.analysis.strong_buy_max_score' => 0, 'market.analysis.previous_buy_max_minutes' => 0]);
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
        ->and($token->signals()->first()->status)->toBe(SignalStatus::Pending)
        ->and($analysis->indicators)->toHaveKey('btc');
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
    $this->freezeTime();
    $sol = Token::factory()->create(['chain_id' => 'solana', 'symbol' => 'SOL', 'is_active' => false]);
    $hour = now()->startOfHour()->timestamp;
    Candle::factory()->for($sol)->create(['timeframe' => '1h', 'open_time' => $hour - 86400, 'close' => 200.0]);
    Candle::factory()->for($sol)->create(['timeframe' => '1h', 'open_time' => $hour, 'close' => 195.0]);
    Token::factory()->create(['price_change_h24' => 4.0]);
    Token::factory()->create(['price_change_h24' => -1.0]);

    $context = app(MarketContext::class)->snapshot();

    expect($context['fear_greed']['value'])->toBe(60)
        ->and($context['benchmarks']['SOL'])->toMatchArray(['price' => 195.0, 'change_24h' => -2.5, 'trend_4h' => null])
        ->and($context['benchmarks']['ETH'])->toBe(['price' => null, 'change_24h' => null, 'trend_4h' => null, 'as_of' => null])
        ->and($context['benchmarks'])->toHaveKey('BTC')
        ->and($context['breadth'])->toMatchArray(['tokens' => 2, 'up_24h_pct' => 50.0]);

    $version = StrategyVersion::current();
    expect($version)->toStartWith('v8-');

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
        ->and(StrategyVersion::current())->toStartWith('v8-')->not->toBe($before);

    $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.analysis.buy_max_rise_24h_pct' => -1]])->assertUnprocessable();
});

test('the v4 limits are part of the strategy version', function () {
    $version = StrategyVersion::current();

    expect($version)->toStartWith('v8-');

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

test('v7 closes an entered signal of the current strategy at the first analysis that is no longer bullish', function () {
    fakeTechnical(10);
    $token = Token::factory()->create(['price_usd' => 1.07]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);
    $signal = Signal::withoutEvents(fn () => Signal::factory()->for($token)->create([
        'status' => 'active', 'max_tp_hit' => 1, 'entry_price' => 1.0, 'entered_at' => now()->subHour(), 'strategy_version' => StrategyVersion::current(),
    ]));

    $analysis = app(SignalEngine::class)->analyze($token);
    $signal->refresh();

    expect($analysis->recommendation->isBullish())->toBeFalse()
        ->and($signal->status)->toBe(SignalStatus::Downgraded)
        ->and($signal->exit_price)->toEqual(1.07)
        ->and($signal->max_tp_hit)->toBe(1)
        ->and($signal->net_r)->not->toBeNull()
        ->and($signal->closed_at)->not->toBeNull()
        ->and($signal->downgrade_price)->toEqual(1.07);
});

test('v7 leaves a v6 signal on its own exits, and the exit can be switched off', function () {
    fakeTechnical(10);
    $old = Token::factory()->create(['price_usd' => 1.07]);
    TokenSecurityCheck::factory()->for($old)->create(['risk_score' => 100]);
    $v6 = Signal::withoutEvents(fn () => Signal::factory()->for($old)->create(['status' => 'active', 'entry_price' => 1.0, 'entered_at' => now()->subHour(), 'strategy_version' => 'v6-2b1128']));

    app(SignalEngine::class)->analyze($old);

    config(['market.analysis.exit_on_downgrade' => false]);
    $current = Token::factory()->create(['price_usd' => 1.07]);
    TokenSecurityCheck::factory()->for($current)->create(['risk_score' => 100]);
    $v7 = Signal::withoutEvents(fn () => Signal::factory()->for($current)->create(['status' => 'active', 'entry_price' => 1.0, 'entered_at' => now()->subHour(), 'strategy_version' => StrategyVersion::current()]));

    app(SignalEngine::class)->analyze($current);

    expect($v6->refresh()->status)->toBe(SignalStatus::Active)
        ->and($v6->downgraded_at)->not->toBeNull()
        ->and($v7->refresh()->status)->toBe(SignalStatus::Active);
});

test('a v7 signal still open after the v8 switch keeps the v7 exit on downgrade', function () {
    fakeTechnical(10);
    $token = Token::factory()->create(['price_usd' => 1.07]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);
    $v7 = Signal::withoutEvents(fn () => Signal::factory()->for($token)->create(['status' => 'active', 'entry_price' => 1.0, 'entered_at' => now()->subHour(), 'strategy_version' => 'v7-a7bb4e']));

    app(SignalEngine::class)->analyze($token);

    expect($v7->refresh()->status)->toBe(SignalStatus::Downgraded);
});

test('v7 makes no signal from a Strong Buy at the score limit and follows it as a shadow with its score', function () {
    fakeTechnical(95);
    $tokens = Token::factory()->count(3)->create(['price_usd' => 1.0]);
    $tokens->each(fn (Token $token) => TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]));
    $score = app(SignalEngine::class)->analyze($tokens[0])->final_score;

    config(['market.analysis.strong_buy_max_score' => $score]);
    $held = app(SignalEngine::class)->analyze($tokens[1]);
    config(['market.analysis.strong_buy_max_score' => $score + 1]);
    app(SignalEngine::class)->analyze($tokens[2]);

    $shadow = ShadowSignal::query()->where('rule', ShadowSignal::HIGH_SCORE)->sole();

    expect($held->recommendation)->toBe(Recommendation::StrongBuy)
        ->and($held->reasons[0])->toContain("Skor {$score} di atas batas sinyal")
        ->and($tokens[1]->signals()->count())->toBe(0)
        ->and($shadow->token_id)->toBe($tokens[1]->id)
        ->and($shadow->metric)->toEqual((float) $score)
        ->and($tokens[2]->signals()->count())->toBe(1)
        // The v7 shadow beside v8 leaves out the same high scores.
        ->and(ShadowSignal::query()->where('rule', ShadowSignal::V7_RULE)->orderBy('token_id')->pluck('token_id')->all())->toBe([$tokens[0]->id, $tokens[2]->id]);
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

test('v6 holds a buy when the top ten holders own at least the limit, and skips the rule without holder data', function (?float $top10, Recommendation $expected) {
    config(['market.analysis.thresholds.STRONG_BUY' => 72]);
    fakeTechnical(95);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100, 'top10_percent' => $top10]);

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation)->toBe($expected)
        ->and($token->signals()->count())->toBe($expected === Recommendation::StrongBuy ? 1 : 0);

    if ($expected === Recommendation::Hold) {
        expect($analysis->reasons[0])->toContain('10 holder teratas memegang '.number_format($top10, 1).'%');
    }
})->with([
    'spread out' => [19.9, Recommendation::StrongBuy],
    'at the limit' => [20.0, Recommendation::Hold],
    'concentrated' => [28.4, Recommendation::Hold],
    'no holder data' => [null, Recommendation::StrongBuy],
]);

test('the v6 holder limit can be switched off and is part of the strategy version', function () {
    $version = StrategyVersion::current();
    expect($version)->toStartWith('v8-');

    config(['market.analysis.max_top10_holder_pct' => 0.0]);
    fakeTechnical(95);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100, 'top10_percent' => 60]);

    expect(app(SignalEngine::class)->analyze($token)->recommendation)->toBe(Recommendation::StrongBuy)
        ->and(StrategyVersion::current())->not->toBe($version);
});

test('a strong buy held by the holder rule is kept as an untraded shadow, and a real signal as the comparison', function () {
    config(['market.analysis.thresholds.STRONG_BUY' => 72]);
    fakeTechnical(95);
    $held = Token::factory()->create(['price_usd' => 1.0, 'liquidity_usd' => 250_000]);
    TokenSecurityCheck::factory()->for($held)->create(['risk_score' => 100, 'top10_percent' => 28.4]);
    $taken = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($taken)->create(['risk_score' => 100, 'top10_percent' => 12]);

    $analysis = app(SignalEngine::class)->analyze($held);
    app(SignalEngine::class)->analyze($taken);

    $shadow = ShadowSignal::query()->where('token_id', $held->id)->sole();
    $reference = ShadowSignal::query()->where('token_id', $taken->id)->where('rule', '!=', ShadowSignal::V7_RULE)->sole();

    expect($analysis->recommendation)->toBe(Recommendation::Hold)
        ->and($analysis->entry_low)->toBeNull()
        ->and($held->signals()->count())->toBe(0)
        ->and($shadow->only(['rule', 'recommendation', 'metric', 'liquidity_usd', 'status']))
        ->toBe(['rule' => 'top10_holders', 'recommendation' => 'STRONG_BUY', 'metric' => 28.4, 'liquidity_usd' => 250000.0, 'status' => 'pending'])
        ->and($shadow->stop_loss)->toBeLessThan($shadow->entry_low)
        ->and($shadow->take_profit_3)->toBeGreaterThan($shadow->take_profit_1)
        ->and($reference->rule)->toBe('accepted')
        ->and($reference->signal_id)->toBe($taken->signals()->sole()->id);
});

test('every buy is followed as an untraded shadow with the fear and greed value, a strong buy is not', function () {
    fakeTechnical(95);
    $buy = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($buy)->create(['risk_score' => 100, 'top10_percent' => 10]);
    $strong = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($strong)->create(['risk_score' => 100, 'top10_percent' => 10]);

    config(['market.analysis.thresholds.STRONG_BUY' => 101]);
    $analysis = app(SignalEngine::class)->analyze($buy);
    config(['market.analysis.thresholds.STRONG_BUY' => 72]);
    app(SignalEngine::class)->analyze($strong);

    $shadow = ShadowSignal::query()->where('rule', 'buy_watch')->sole();

    expect($analysis->recommendation)->toBe(Recommendation::Buy)
        ->and($buy->signals()->count())->toBe(0)
        ->and($shadow->only(['token_id', 'recommendation', 'metric', 'status']))->toBe(['token_id' => $buy->id, 'recommendation' => 'BUY', 'metric' => 60.0, 'status' => 'pending'])
        ->and($shadow->stop_loss)->toBeLessThan($shadow->entry_low);
});

test('no shadow is kept for a held buy, which opens no signal anyway, or while the holder rule is off', function (array $config) {
    config($config);
    fakeTechnicalMove(10.0, 8.0);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100, 'top10_percent' => 40]);

    app(SignalEngine::class)->analyze($token);

    expect(ShadowSignal::query()->where('rule', 'top10_holders')->count())->toBe(0);
})->with([
    'buy' => [['market.analysis.thresholds.STRONG_BUY' => 101]],
    'rule off' => [['market.analysis.thresholds.STRONG_BUY' => 72, 'market.analysis.max_top10_holder_pct' => 0.0]],
]);

/**
 * A token whose analysis comes out Strong Sell: technical 5 with neutral other scores.
 */
function strongSellToken(): Token
{
    fakeTechnical(5);
    config(['market.analysis.thresholds.SELL' => 30, 'market.analysis.thresholds.HOLD' => 90]);
    $token = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    return $token;
}

test('a sell turns every holding of the token into a market sale and cancels waiting orders, signals untouched', function () {
    $token = strongSellToken();
    $owner = User::factory()->create(['balance_usd' => 0]);
    $robot = User::factory()->create(['balance_usd' => 0, 'is_system' => true]);
    foreach ([$owner, $robot] as $user) {
        Position::query()->create(['user_id' => $user->id, 'token_id' => $token->id, 'quantity' => 50, 'avg_price' => 1.6, 'realized_pnl' => 0]);
        Order::query()->create(['user_id' => $user->id, 'token_id' => $token->id, 'side' => 'sell', 'type' => 'stop_loss', 'status' => 'pending', 'quantity' => 50, 'trigger_price' => 1.2]);
    }
    $signal = Signal::factory()->for($token)->active()->create();
    $this->mock(PriceCrossCheck::class, fn ($mock) => $mock->shouldReceive('priceToActOn')->andReturn(new CheckedPrice(2.0, 2.0, CheckedPrice::FEED)));

    $analysis = app(SignalEngine::class)->analyze($token);

    expect($analysis->recommendation->isBullish())->toBeFalse()
        ->and(in_array($analysis->recommendation->value, ['SELL', 'STRONG_SELL', 'AVOID'], true))->toBeTrue()
        ->and(Position::query()->where('token_id', $token->id)->sum('quantity'))->toEqual(0)
        ->and(Order::query()->where('type', 'stop_loss')->pluck('status')->map->value->unique()->all())->toBe(['cancelled'])
        ->and(Order::query()->where('type', 'market')->where('status', 'filled')->count())->toBe(2)
        ->and(Order::query()->where('type', 'market')->first()->note)->toContain('Ditutup otomatis: analisa')
        ->and($owner->refresh()->balance_usd)->toBeGreaterThan(90.0)
        ->and($signal->refresh()->status)->toBe(SignalStatus::Active);
});

test('nothing is sold while the rule is off, on a hold, or without a fresh price', function (string $case) {
    $token = strongSellToken();
    match ($case) {
        'off' => config(['market.simulator.close_on_sell' => false]),
        'hold' => fakeTechnical(60),
        'stale' => $token->update(['price_updated_at' => now()->subHour()]),
    };
    $user = User::factory()->create();
    Position::query()->create(['user_id' => $user->id, 'token_id' => $token->id, 'quantity' => 50, 'avg_price' => 1.6, 'realized_pnl' => 0]);

    app(SignalEngine::class)->analyze($token->refresh());

    expect(Position::query()->where('token_id', $token->id)->value('quantity'))->toEqual(50);
})->with(['off', 'hold', 'stale']);

test('without a checked price the sale waits one analysis cycle, then goes ahead on the feed', function () {
    $token = strongSellToken();
    $user = User::factory()->create(['balance_usd' => 0]);
    Position::query()->create(['user_id' => $user->id, 'token_id' => $token->id, 'quantity' => 50, 'avg_price' => 1.6, 'realized_pnl' => 0]);
    $this->mock(PriceCrossCheck::class, fn ($mock) => $mock->shouldReceive('priceToActOn')->andReturn(new CheckedPrice(2.0, null, CheckedPrice::UNCHECKED)));
    $held = fn (): float => (float) Position::query()->where('token_id', $token->id)->value('quantity');

    app(SignalEngine::class)->analyze($token);
    expect($held())->toEqual(50.0);

    $this->travel(15)->minutes();
    $token->update(['price_updated_at' => now()]);
    app(SignalEngine::class)->analyze($token->refresh());

    expect($held())->toEqual(0.0)
        ->and(OrderFillEvidence::query()->latest('id')->value('source'))->toBe(CheckedPrice::UNCHECKED);
});

test('v8 makes a signal only from a Strong Buy that follows a Buy or Strong Buy analysis', function () {
    config(['market.analysis.previous_buy_max_minutes' => 20]);
    fakeTechnical(95);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);

    // The first Strong Buy stays one on the page, makes no signal, and is followed as a shadow.
    $first = app(SignalEngine::class)->analyze($token);
    expect($first->recommendation)->toBe(Recommendation::StrongBuy)
        ->and($token->signals()->count())->toBe(0)
        ->and($first->reasons[0])->toContain('(v8)')
        ->and(ShadowSignal::query()->where('rule', ShadowSignal::FIRST_BUY)->sole())->analysis_id->toBe($first->id);

    // Fifteen minutes later the next Strong Buy is confirmed and trades.
    $this->travel(15)->minutes();
    $second = app(SignalEngine::class)->analyze($token);
    expect($token->signals()->sole())->analysis_id->toBe($second->id)
        ->and(ShadowSignal::query()->where('rule', ShadowSignal::FIRST_BUY)->count())->toBe(1)
        // v7 runs beside v8 as a shadow: both Strong Buys are what v7 would have traded.
        ->and(ShadowSignal::query()->where('rule', ShadowSignal::V7_RULE)->pluck('analysis_id')->all())->toBe([$first->id, $second->id]);
});

test('v8 does not count a Buy analysis older than the limit, or a Hold, as the confirmation', function () {
    config(['market.analysis.previous_buy_max_minutes' => 20]);
    fakeTechnical(95);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    TokenSecurityCheck::factory()->for($token)->create(['risk_score' => 100]);
    Analysis::factory()->for($token)->create(['recommendation' => Recommendation::StrongBuy, 'created_at' => now()->subMinutes(45)]);

    app(SignalEngine::class)->analyze($token);
    expect($token->signals()->count())->toBe(0);

    $this->travel(15)->minutes();
    Analysis::factory()->for($token)->create(['recommendation' => Recommendation::Hold]);
    $this->travel(1)->minutes();
    app(SignalEngine::class)->analyze($token);

    expect($token->signals()->count())->toBe(0)
        ->and(ShadowSignal::query()->where('rule', ShadowSignal::FIRST_BUY)->count())->toBe(2);
});

test('the v8 limit is part of the strategy version', function () {
    $version = StrategyVersion::current();
    config(['market.analysis.previous_buy_max_minutes' => 30]);

    expect(StrategyVersion::current())->not->toBe($version);
});
