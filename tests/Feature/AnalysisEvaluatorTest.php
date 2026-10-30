<?php

use App\Enums\Recommendation;
use App\Models\Analysis;
use App\Models\AnalysisEvaluation;
use App\Models\Candle;
use App\Models\Token;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00'));
    $this->token = Token::factory()->create();
});

/**
 * 15m candles from $from for $hours, with closes following $price(minutesSinceStart).
 */
function candles(Token $token, Carbon $from, int $hours, Closure $price): void
{
    $start = intdiv($from->getTimestamp(), 900) * 900;

    for ($t = $start; $t <= $start + $hours * 3600; $t += 900) {
        $close = $price(($t - $start) / 60);
        Candle::factory()->create(['token_id' => $token->id, 'timeframe' => '15m', 'open_time' => $t, 'open' => $close, 'high' => $close * 1.01, 'low' => $close * 0.99, 'close' => $close]);
    }
}

test('analyses are recorded once with their scores', function () {
    $analysis = Analysis::factory()->for($this->token)->create(['recommendation' => Recommendation::StrongBuy, 'final_score' => 78, 'price' => 2.0]);

    $this->artisan('market:evaluate')->assertSuccessful();
    $this->artisan('market:evaluate')->assertSuccessful();

    $evaluation = AnalysisEvaluation::sole();
    expect($evaluation->analysis_id)->toBe($analysis->id)
        ->and($evaluation->recommendation)->toBe(Recommendation::StrongBuy)
        ->and($evaluation->final_score)->toBe(78)
        ->and($evaluation->scores)->toHaveKeys(['technical', 'onchain', 'whale', 'sentiment', 'fundamental']);
});

test('returns are filled as each horizon passes, then the 24h range completes it', function () {
    $analyzedAt = now()->subHours(5);
    Analysis::factory()->for($this->token)->create(['price' => 1.0, 'created_at' => $analyzedAt]);
    candles($this->token, $analyzedAt, 30, fn (float $minutes): float => 1 + $minutes / 6000);

    $this->artisan('market:evaluate');
    $evaluation = AnalysisEvaluation::sole();

    expect($evaluation->return_1h)->toEqualWithDelta(1.0, 0.3)
        ->and($evaluation->return_4h)->toEqualWithDelta(4.0, 0.3)
        ->and($evaluation->return_24h)->toBeNull()
        ->and($evaluation->completed_at)->toBeNull();

    Carbon::setTestNow(now()->addDay());
    $this->artisan('market:evaluate');
    $evaluation->refresh();

    expect($evaluation->return_24h)->toEqualWithDelta(24.0, 0.3)
        ->and($evaluation->max_up_24h)->toBeGreaterThan($evaluation->return_24h)
        ->and($evaluation->max_down_24h)->toBeLessThan(0)
        ->and($evaluation->completed_at)->not->toBeNull();
});

test('a token that stopped syncing is closed after 48 hours with what exists', function () {
    Analysis::factory()->for($this->token)->create(['price' => 1.0, 'created_at' => now()->subHours(49)]);
    candles($this->token, now()->subHours(49), 2, fn (): float => 1.1);

    $this->artisan('market:evaluate', ['--days' => 3]);
    $evaluation = AnalysisEvaluation::sole();

    expect($evaluation->return_1h)->toEqualWithDelta(10.0, 0.01)
        ->and($evaluation->return_24h)->toBeNull()
        ->and($evaluation->completed_at)->not->toBeNull();
});

test('evaluations survive when the analysis itself is pruned', function () {
    $analysis = Analysis::factory()->for($this->token)->create();
    $this->artisan('market:evaluate');

    $analysis->delete();

    expect(AnalysisEvaluation::sole()->analysis_id)->toBeNull();
});
