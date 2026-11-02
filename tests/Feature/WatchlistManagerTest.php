<?php

use App\Enums\OrderStatus;
use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Enums\WatchlistAction;
use App\Jobs\FollowSignalAutomatically;
use App\Models\Analysis;
use App\Models\Order;
use App\Models\Position;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Analysis\SignalEngine;
use App\Services\Analysis\WatchlistManager;
use App\Services\Trading\TradingService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * One analysis per day, oldest first, ending today.
 *
 * @param  list<int>  $scores
 */
function scoreHistory(Token $token, array $scores, Recommendation $recommendation = Recommendation::Hold): void
{
    $days = count($scores);

    foreach ($scores as $i => $score) {
        Analysis::factory()->for($token)->create([
            'final_score' => $score,
            'recommendation' => $recommendation,
            'created_at' => now()->subDays($days - 1 - $i)->setTime(12, 0),
        ]);
    }
}

test('trend detects a steady weekly decline but ignores noise', function () {
    $days = fn (array $scores): array => array_map(fn ($s, $i) => ['day' => "d{$i}", 'score' => (float) $s], $scores, array_keys($scores));

    expect(WatchlistManager::trend($days([62, 58, 55, 50, 47, 44, 41]))['declining'])->toBeTrue()
        ->and(WatchlistManager::trend($days([55, 50, 56, 49, 55, 50, 54]))['declining'])->toBeFalse()
        ->and(WatchlistManager::trend($days([60, 45]))['declining'])->toBeFalse();
});

test('declining unpinned tokens nobody holds are removed with a reason', function () {
    $token = Token::factory()->create(['is_pinned' => false, 'source' => 'discovery']);
    scoreHistory($token, [62, 58, 55, 50, 47, 44, 41]);

    $removed = app(WatchlistManager::class)->prune();

    expect($removed)->toBe([$token->symbol])
        ->and($token->refresh()->is_active)->toBeFalse()
        ->and($token->watchlistEvents()->first())
        ->action->toBe(WatchlistAction::Removed)
        ->reason->toContain('Skor turun 21 poin');
});

test('pinned tokens and tokens in a portfolio stay even when declining', function () {
    $pinned = Token::factory()->create(['is_pinned' => true]);
    $held = Token::factory()->create(['is_pinned' => false]);
    $ordered = Token::factory()->create(['is_pinned' => false]);

    foreach ([$pinned, $held, $ordered] as $token) {
        scoreHistory($token, [62, 58, 55, 50, 47, 44, 41]);
    }

    Position::factory()->for($held)->for(User::factory())->create(['quantity' => 10]);
    Order::factory()->for($ordered)->create(['status' => OrderStatus::Pending, 'side' => 'sell', 'type' => 'stop_loss']);

    expect(app(WatchlistManager::class)->prune())->toBe([])
        ->and(Token::query()->active()->count())->toBe(3);
});

test('a declining token that still scores a buy is kept', function () {
    $token = Token::factory()->create(['is_pinned' => false]);
    scoreHistory($token, [85, 80, 76, 72, 70, 66, 64]);

    expect(app(WatchlistManager::class)->prune())->toBe([]);
});

test('too little history is never judged as a decline', function () {
    $token = Token::factory()->create(['is_pinned' => false]);
    scoreHistory($token, [60, 40]);

    expect(app(WatchlistManager::class)->prune())->toBe([]);
});

test('avoid or strong sell recommendations leave immediately', function () {
    $token = Token::factory()->create(['is_pinned' => false]);
    scoreHistory($token, [20], Recommendation::Avoid);

    expect(app(WatchlistManager::class)->prune())->toBe([$token->symbol]);
});

test('a token in use by any account cannot be removed, by any path', function (string $use) {
    $user = User::factory()->create();
    $token = Token::factory()->create(['is_pinned' => false]);

    match ($use) {
        'position' => Position::factory()->for($token)->for($user)->create(['quantity' => 10]),
        'pending order' => Order::factory()->for($token)->create(['status' => OrderStatus::Pending]),
        'auto-follow order' => Order::factory()->for($token)->for(User::autoFollower('buy'))->create(['status' => OrderStatus::Pending]),
        'open signal' => Signal::factory()->for($token)->create(['status' => SignalStatus::Pending]),
    };

    $manager = app(WatchlistManager::class);

    expect($manager->remove($token, 'uji'))->toBeFalse()
        ->and($token->fresh()->is_active)->toBeTrue()
        ->and($token->watchlistEvents()->where('action', WatchlistAction::Removed)->exists())->toBeFalse()
        ->and($manager->replaceable())->toBeNull();

    $this->actingAs($user)->deleteJson("/api/tokens/{$token->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'belum bisa dikeluarkan'));

    expect($token->fresh()->is_active)->toBeTrue();
})->with(['position', 'pending order', 'auto-follow order', 'open signal']);

test('a token nobody uses can still be removed by hand', function () {
    $user = User::factory()->create();
    $token = Token::factory()->create(['is_pinned' => false]);
    Order::factory()->for($token)->create(['status' => OrderStatus::Filled]);

    $this->actingAs($user)->deleteJson("/api/tokens/{$token->id}")->assertOk();

    expect($token->fresh()->is_active)->toBeFalse()
        ->and($token->watchlistEvents()->where('action', WatchlistAction::Removed)->exists())->toBeTrue();
});

test('a replacement stops when the weakest token gained an order at the last moment', function () {
    $token = Token::factory()->create(['is_pinned' => false]);
    $manager = app(WatchlistManager::class);

    expect($manager->replaceable()?->id)->toBe($token->id);

    Order::factory()->for($token)->create(['status' => OrderStatus::Pending]);

    expect($manager->remove($token, 'Digantikan'))->toBeFalse()
        ->and($token->fresh()->is_active)->toBeTrue();
});

test('orders cannot be placed on a token outside the market', function (array $data) {
    $user = User::factory()->create(['balance_usd' => 1000]);
    $token = Token::factory()->create(['is_active' => false, 'price_usd' => 1.0]);
    Position::factory()->for($token)->for($user)->create(['quantity' => 10]);

    expect(fn () => app(TradingService::class)->place($user, $token, $data))
        ->toThrow(ValidationException::class, 'tidak lagi ada di Market');

    expect($token->orders()->count())->toBe(0);
})->with([
    'market buy' => [['side' => 'buy', 'type' => 'market', 'amount_usd' => 10]],
    'limit buy' => [['side' => 'buy', 'type' => 'limit', 'amount_usd' => 10, 'trigger_price' => 0.5]],
    'market sell' => [['side' => 'sell', 'type' => 'market', 'quantity' => 1]],
]);

test('an order placed with an old copy of the token is refused once it left the market', function () {
    $user = User::factory()->create(['balance_usd' => 1000]);
    $token = Token::factory()->create(['price_usd' => 1.0]);
    $stale = Token::query()->find($token->id);

    app(WatchlistManager::class)->remove($token, 'uji');

    expect(fn () => app(TradingService::class)->place($user, $stale, ['side' => 'buy', 'type' => 'limit', 'amount_usd' => 10, 'trigger_price' => 0.5]))
        ->toThrow(ValidationException::class);
});

test('an analysis queued before the token left creates no analysis, signal or auto-follow order', function () {
    Queue::fake();
    $token = Token::factory()->create(['price_usd' => 1.0]);
    $stale = Token::query()->find($token->id);

    app(WatchlistManager::class)->remove($token, 'uji');

    expect(app(SignalEngine::class)->analyze($stale))->toBeNull()
        ->and($token->signals()->count())->toBe(0)
        ->and($token->analyses()->count())->toBe(0);

    Queue::assertNotPushed(FollowSignalAutomatically::class);
});

test('market api exposes score history, capacity and pinning', function () {
    $user = User::factory()->create();
    $token = Token::factory()->create(['is_pinned' => false]);
    scoreHistory($token, [50, 55, 60]);

    $this->actingAs($user)->getJson('/api/tokens')
        ->assertOk()
        ->assertJsonPath('capacity.used', 1)
        ->assertJsonCount(3, 'data.0.score_history')
        ->assertJsonPath('data.0.score_trend.change', 10);

    $this->actingAs($user)->patchJson("/api/tokens/{$token->id}/pin", ['pinned' => true])
        ->assertOk()
        ->assertJsonPath('data.is_pinned', true);

    $this->actingAs($user)->getJson('/api/watchlist/events')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'pinned');
});
