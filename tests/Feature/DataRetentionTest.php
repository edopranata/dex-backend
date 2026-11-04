<?php

use App\Models\Analysis;
use App\Models\Signal;
use App\Models\Token;
use App\Models\WatchlistEvent;
use Illuminate\Support\Facades\Artisan;

function agedAnalysis(Token $token, int $days): Analysis
{
    $analysis = Analysis::factory()->for($token)->create();
    $analysis->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();

    return $analysis;
}

test('analyses stay 90 days, and those behind a signal stay for good', function () {
    $token = Token::factory()->create();
    $month = agedAnalysis($token, 30);
    $quarter = agedAnalysis($token, 89);
    $old = agedAnalysis($token, 91);
    $withSignal = agedAnalysis($token, 400);
    Signal::factory()->for($token)->create(['analysis_id' => $withSignal->id]);

    Artisan::call('model:prune', ['--model' => [Analysis::class]]);

    expect(Analysis::query()->pluck('id')->all())->toEqualCanonicalizing([$month->id, $quarter->id, $withSignal->id])
        ->and($old->fresh())->toBeNull();
});

test('market events are never pruned, because the backtest needs when a token was in the market', function () {
    $event = WatchlistEvent::factory()->create();
    $event->forceFill(['created_at' => now()->subYears(2)])->saveQuietly();

    Artisan::call('model:prune');

    expect($event->fresh())->not->toBeNull();
});
