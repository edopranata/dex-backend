<?php

use App\Models\EquitySnapshot;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Analysis\StrategyVersion;
use App\Services\Trading\TradingService;

beforeEach(function () {
    app(SettingsStore::class)->set(['market.autofollow.enabled' => true, 'market.autofollow.amount_usd' => 100, 'market.autofollow.risk_pct' => 0, 'market.autofollow.max_open_positions' => 0, 'market.simulator.starting_balance' => 10_000]);
    foreach (array_keys(User::AUTO_FOLLOW_ACCOUNTS) as $key) {
        User::autoFollower($key)->forceFill(['created_at' => now()->subDay()])->save();
    }
    // Through the admin settings, which the API applies on every request.
    app(SettingsStore::class)->set(['market.selection.min_trades' => 2, 'market.selection.min_win_rate' => 50.0]);
    $this->person = User::factory()->create();
});

/**
 * A Buy signal the "buy" account fills at 2.0 (stop 1.8, targets 2.3, 2.5, 2.8), then closed at the stop or after TP1.
 */
function selectedLot(bool $won, ?string $version = null): Order
{
    $token = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now(), 'liquidity_usd' => 10_000_000]);
    $signal = Signal::factory()->for($token)->active(2.0)->create(['recommendation' => 'BUY', 'entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8,
        'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8, 'strategy_version' => $version ?? StrategyVersion::current()]);
    $buy = User::autoFollower('buy')->orders()->where('signal_id', $signal->id)->where('side', 'buy')->sole();
    $exit = fn (string $type): Order => Order::where('parent_id', $buy->id)->where('type', $type)->where('status', 'pending')->orderBy('id')->firstOrFail();
    $trading = app(TradingService::class);

    if ($won) {
        $trading->execute($exit('take_profit'), 2.5);
        $trading->execute($exit('take_profit'), 2.5);
        $trading->execute($exit('take_profit'), 2.8);
    } else {
        $trading->execute($exit('stop_loss'), 1.8);
    }

    return $buy;
}

/**
 * @return array<string, mixed>
 */
function selectionOf(string $key): array
{
    $response = test()->actingAs(test()->person)->getJson('/api/reports/selection')->assertOk();

    return collect($response->json('accounts'))->keyBy('key')->get($key);
}

test('an account is judged on the lots of the current strategy version, with every version shown beside it', function () {
    selectedLot(won: true);
    selectedLot(won: true);
    selectedLot(won: false, version: 'v7-abc123');

    $this->getJson('/api/reports/selection')->assertUnauthorized();
    $row = selectionOf('buy');

    expect($row)->toMatchArray(['status' => 'active', 'closed' => 2, 'wins' => 2, 'losses' => 0, 'win_rate' => 100, 'reasons' => []])
        ->and($row['avg_r'])->toBeGreaterThan(2)
        ->and($row['all_versions'])->toMatchArray(['closed' => 3, 'win_rate' => 66.7])
        ->and(selectionOf('strong_buy'))->toMatchArray(['status' => 'insufficient', 'closed' => 0, 'reasons' => ['Baru 0 dari 2 lot selesai di versi ini']]);
});

test('a losing account fails on its R, and a drawdown in the equity history keeps it out too', function () {
    // +2.93R once, -1.06R three times: about -0.06R per lot.
    selectedLot(won: true);
    selectedLot(won: false);
    selectedLot(won: false);
    selectedLot(won: false);
    EquitySnapshot::query()->create(['user_id' => User::autoFollower('buy')->id, 'equity_usd' => 12_000, 'balance_usd' => 12_000, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()->subHours(2)]);
    EquitySnapshot::query()->create(['user_id' => User::autoFollower('buy')->id, 'equity_usd' => 9_000, 'balance_usd' => 9_000, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()->subHour()]);

    $row = selectionOf('buy');

    expect($row['status'])->toBe('failed')
        ->and($row['win_rate'])->toEqual(25)
        ->and($row['drawdown_pct'])->toEqual(25)
        ->and($row['reasons'])->toHaveCount(3)
        ->and($row['reasons'][0])->toBe('Win rate 25% di bawah 50%')
        ->and($row['reasons'][1])->toStartWith('Rata-rata -0.0')
        ->and($row['reasons'][2])->toBe('Drawdown 25% tidak di bawah 15%');
});
