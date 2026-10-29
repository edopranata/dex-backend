<?php

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Models\Token;
use App\Models\TokenSecurityCheck;
use App\Services\Analysis\SignalEngine;
use App\Services\Analysis\TechnicalAnalyzer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['api.alternative.me/*' => Http::response(['data' => [['value' => '60', 'value_classification' => 'Greed', 'timestamp' => '0']]])]);
    Http::preventStrayRequests();
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
