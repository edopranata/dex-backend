<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['api.alternative.me/*' => Http::response(['data' => [['value' => '50', 'value_classification' => 'Neutral', 'timestamp' => '0']]])]);
});

test('guests cannot access the api', function () {
    $this->getJson('/api/tokens')->assertUnauthorized();
});

test('there is no public registration; accounts are created by admins', function () {
    $this->getJson('/api/auth/options')->assertOk()->assertJsonMissingPath('registration_enabled');

    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/auth/register', ['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertNotFound();

    expect(User::count())->toBe(0);
});

test('login rejects wrong credentials', function () {
    $user = User::factory()->create();

    $this->withHeader('Origin', 'http://localhost:5173')
        ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('token list and detail include the latest analysis', function () {
    $user = User::factory()->create();
    $token = Token::factory()->create();

    $this->actingAs($user)->getJson('/api/tokens')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user)->getJson("/api/tokens/{$token->id}")->assertOk()->assertJsonPath('data.symbol', $token->symbol);
    $this->actingAs($user)->getJson('/api/market/overview')->assertOk()->assertJsonPath('fear_greed.value', 50);
});

test('search hides illiquid and unsupported pairs', function () {
    Http::fake(['api.dexscreener.com/*' => Http::response(['pairs' => [
        ['chainId' => 'solana', 'pairAddress' => 'good', 'baseToken' => ['symbol' => 'GOOD', 'name' => 'Good'], 'quoteToken' => ['symbol' => 'SOL'], 'liquidity' => ['usd' => 500_000], 'priceUsd' => '1'],
        ['chainId' => 'solana', 'pairAddress' => 'thin', 'baseToken' => ['symbol' => 'THIN', 'name' => 'Thin'], 'quoteToken' => ['symbol' => 'SOL'], 'liquidity' => ['usd' => 500], 'priceUsd' => '1'],
        ['chainId' => 'tron', 'pairAddress' => 'tron', 'baseToken' => ['symbol' => 'TRX', 'name' => 'Tron'], 'quoteToken' => ['symbol' => 'USDT'], 'liquidity' => ['usd' => 900_000], 'priceUsd' => '1'],
    ]])]);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/tokens/search?q=good')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.symbol', 'GOOD');
});

test('market order through the api fills and updates the portfolio', function () {
    $user = User::factory()->create(['balance_usd' => 1000]);
    $token = Token::factory()->create(['price_usd' => 1.0]);

    $this->actingAs($user)
        ->postJson('/api/orders', ['token_id' => $token->id, 'side' => 'buy', 'type' => 'market', 'amount_usd' => 100])
        ->assertCreated()
        ->assertJsonPath('data.status', 'filled');

    $this->actingAs($user)
        ->getJson('/api/portfolio')
        ->assertOk()
        ->assertJsonPath('balance_usd', 900)
        ->assertJsonCount(1, 'positions');
});

test('buy orders cannot be stop loss orders', function () {
    $token = Token::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson('/api/orders', ['token_id' => $token->id, 'side' => 'buy', 'type' => 'stop_loss', 'amount_usd' => 100, 'trigger_price' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

test('following a signal opens the position with stop loss and three targets', function () {
    $user = User::factory()->create(['balance_usd' => 1000]);
    $signal = Signal::factory()->create();
    $signal->token->update(['price_usd' => 1.0]);

    $this->actingAs($user)
        ->postJson("/api/signals/{$signal->id}/follow", ['amount_usd' => 300])
        ->assertOk()
        ->assertJsonCount(5, 'data');

    expect(Order::where('status', OrderStatus::Pending)->count())->toBe(4)
        ->and(Order::where('type', 'take_profit')->sum('quantity'))->toEqualWithDelta(Order::where('type', 'stop_loss')->value('quantity'), 1e-6);
});

test('users cannot cancel orders of other users', function () {
    $order = Order::factory()->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/orders/{$order->id}")
        ->assertNotFound();
});

test('signal stats summarize closed signals', function () {
    Signal::factory()->active()->create(['status' => 'take_profit', 'result_r' => 2.0, 'max_tp_hit' => 3, 'closed_at' => now()]);
    Signal::factory()->active()->create(['status' => 'stop_loss', 'result_r' => -1.0, 'closed_at' => now()]);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/signals/stats')
        ->assertOk()
        ->assertJsonPath('win_rate', 50)
        ->assertJsonPath('total_r', 1);
});

test('admins create users from the command line', function () {
    $this->artisan('app:create-user', ['--name' => 'Edo', '--email' => 'edo@example.com', '--password' => 'rahasia-kuat-123'])
        ->assertSuccessful();

    expect(User::firstWhere('email', 'edo@example.com'))->balance_usd->toEqual(config('market.simulator.starting_balance'));
});

test('api answers 401 json for guests even without an accept header', function () {
    $this->get('/api/tokens')->assertUnauthorized();
});

test('spa routes are served by laravel while api routes are not', function () {
    $index = tempnam(sys_get_temp_dir(), 'spa');
    file_put_contents($index, '<!doctype html><div id="app"></div>');
    config(['market.spa_index' => $index]);

    $this->get('/markets')->assertSuccessful()->assertSee('<div id="app">', false);
    $this->get('/api/does-not-exist')->assertNotFound();
});

test('a missing frontend build explains how to build it', function () {
    config(['market.spa_index' => '/nonexistent/index.html']);

    $this->get('/')->assertStatus(503)->assertSee('npm run build:laravel');
});
