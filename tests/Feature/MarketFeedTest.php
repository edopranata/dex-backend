<?php

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\WatchlistAction;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Models\WatchlistEvent;
use App\Models\WhaleTrade;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = Token::factory()->create(['symbol' => 'PEPE', 'price_usd' => 2.0, 'price_change_h24' => 4.5]);
    $this->since = now()->subMinute()->getTimestamp();
    $this->travel(1)->seconds();
});

function feedSignal(Token $token, array $attributes = []): Signal
{
    return Signal::withoutEvents(fn () => Signal::factory()->for($token)->create(['recommendation' => 'BUY', 'status' => 'pending', ...$attributes]));
}

function feedTitles(mixed $test, ?int $since = null): array
{
    return $test->actingAs($test->user)->getJson('/api/feed?since='.($since ?? $test->since))->assertOk()->json('events.*.title');
}

test('the feed needs a login', function () {
    $this->getJson('/api/feed')->assertUnauthorized();
});

test('the running text lists open buy signals of active tokens, strongest first, once per token', function () {
    feedSignal($this->token);
    feedSignal($this->token);
    $strong = Token::factory()->create(['symbol' => 'BONK']);
    feedSignal($strong, ['recommendation' => 'STRONG_BUY']);
    feedSignal(Token::factory()->create(['symbol' => 'SELLME']), ['recommendation' => 'SELL']);
    feedSignal(Token::factory()->create(['symbol' => 'OLD', 'is_active' => false]));
    feedSignal(Token::factory()->create(['symbol' => 'DONE']), ['status' => 'stop_loss', 'closed_at' => now()]);

    $this->actingAs($this->user)->getJson('/api/feed')
        ->assertOk()
        ->assertJsonCount(2, 'ticker')
        ->assertJsonPath('ticker.0.token.symbol', 'BONK')
        ->assertJsonPath('ticker.1.token.symbol', 'PEPE')
        ->assertJsonPath('ticker.1.token.change_h24', 4.5);
});

test('the first call only starts the clock', function () {
    feedSignal($this->token);

    $this->actingAs($this->user)->getJson('/api/feed')->assertJsonPath('events', []);
});

test('consecutive windows show an event once, with nothing lost at the edge', function () {
    feedSignal($this->token);

    $first = $this->actingAs($this->user)->getJson('/api/feed?since='.$this->since)->json();
    expect($first['events'])->toBeEmpty();

    $this->travel(1)->seconds();
    $second = $this->actingAs($this->user)->getJson('/api/feed?since='.$first['server_time'])->json();
    expect($second['events'])->toHaveCount(1);

    $this->travel(1)->seconds();
    expect($this->actingAs($this->user)->getJson('/api/feed?since='.$second['server_time'])->json('events'))->toBeEmpty();
});

test('signals are announced when created, entered and closed, but not when they expire', function () {
    $this->travel(1)->seconds();
    feedSignal($this->token, ['recommendation' => 'STRONG_BUY']);
    feedSignal($this->token, ['entry_price' => 2, 'entered_at' => now()]);
    feedSignal($this->token, ['status' => 'take_profit', 'net_r' => 1.5, 'closed_at' => now()]);
    feedSignal($this->token, ['status' => 'stop_loss', 'net_r' => -1, 'closed_at' => now()]);
    $expired = feedSignal($this->token, ['status' => 'expired', 'closed_at' => now()]);
    $this->travel(1)->seconds();

    $titles = feedTitles($this);

    expect($titles)->toContain('Sinyal Strong Buy: PEPE', 'Entry tercapai: PEPE', 'Sinyal kena take profit: PEPE', 'Sinyal kena stop loss: PEPE')
        ->and(collect($this->actingAs($this->user)->getJson('/api/feed?since='.$this->since)->json('events'))->pluck('id'))->not->toContain("signal:{$expired->id}:closed");
});

test('tokens entering and leaving the market are announced, pins are not', function () {
    foreach ([WatchlistAction::Added, WatchlistAction::Removed, WatchlistAction::Pinned] as $action) {
        WatchlistEvent::withoutEvents(fn () => $this->token->watchlistEvents()->create(['action' => $action, 'reason' => 'Skor tinggi.']));
    }
    $this->travel(1)->seconds();

    expect(feedTitles($this))->toBe(['Masuk Market: PEPE', 'Keluar dari Market: PEPE']);
});

test('whale trades above the threshold are merged per token and side, by the time they were recorded', function () {
    config(['market.feed.whale_min_usd' => 100000]);
    $make = fn (float $usd, OrderSide $side) => WhaleTrade::factory()->for($this->token)->create(['amount_usd' => $usd, 'side' => $side, 'traded_at' => now()->subHours(3)]);
    $make(100000, OrderSide::Buy);
    $make(300000, OrderSide::Buy);
    $make(99000, OrderSide::Buy);
    $make(150000, OrderSide::Sell);
    $this->travel(1)->seconds();

    $events = $this->actingAs($this->user)->getJson('/api/feed?since='.$this->since)->json('events');

    expect(collect($events)->pluck('title')->all())->toBe(['Whale membeli PEPE', 'Whale menjual PEPE'])
        ->and($events[0]['body'])->toContain('2 transaksi');
});

test('a user only hears about automatic fills of their own orders', function () {
    $fill = fn (User $user, OrderType $type) => Order::factory()->for($user)->for($this->token)->create(['side' => OrderSide::Sell, 'type' => $type, 'status' => OrderStatus::Filled, 'filled_price' => 2, 'total_usd' => 50, 'filled_at' => now()]);
    $fill($this->user, OrderType::StopLoss);
    $fill($this->user, OrderType::Market);
    $fill(User::factory()->create(), OrderType::TakeProfit);
    $this->travel(1)->seconds();

    expect(feedTitles($this))->toBe(['Stop loss tereksekusi: PEPE']);
});

test('a tab that was hidden for hours does not replay old events', function () {
    feedSignal($this->token);
    $this->travel(2)->hours();

    expect(feedTitles($this))->toBeEmpty();
});
