<?php

use App\Enums\OrderStatus;
use App\Models\AutoFollowDecision;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Trading\TradingService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    app(SettingsStore::class)->set(['market.autofollow.enabled' => true, 'market.autofollow.amount_usd' => 100, 'market.autofollow.risk_pct' => 0, 'market.autofollow.max_open_positions' => 0, 'market.simulator.starting_balance' => 10_000]);
    // The accounts exist before the signals they follow.
    foreach (array_keys(User::AUTO_FOLLOW_ACCOUNTS) as $key) {
        User::autoFollower($key)->forceFill(['created_at' => now()->subDay()])->save();
    }
    $this->person = User::factory()->create();
});

/**
 * A Buy signal on its own token that the "buy" account fills straight away at 2.0: stop 1.8, targets 2.3, 2.5, 2.8.
 *
 * @return array{0: Signal, 1: Order}
 */
function followedBuy(): array
{
    $token = Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now(), 'liquidity_usd' => 10_000_000]);
    $signal = Signal::factory()->for($token)->active(2.0)->create(['recommendation' => 'BUY', 'entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8, 'take_profit_1' => 2.3, 'take_profit_2' => 2.5, 'take_profit_3' => 2.8]);

    return [$signal, User::autoFollower('buy')->orders()->where('signal_id', $signal->id)->where('side', 'buy')->sole()];
}

function exitOf(Order $buy, string $type): Order
{
    return Order::where('parent_id', $buy->id)->where('type', $type)->where('status', 'pending')->orderBy('id')->firstOrFail();
}

test('the account report judges each lot on what the account itself earned', function () {
    $trading = app(TradingService::class);

    // Target 1, then the stop at the entry.
    [$breakeven, $breakevenBuy] = followedBuy();
    $trading->execute(exitOf($breakevenBuy, 'take_profit'), 2.3);
    $trading->execute(exitOf($breakevenBuy, 'stop_loss'), 2.0);
    $breakeven->update(['status' => 'take_profit', 'max_tp_hit' => 1, 'net_r' => 0.4, 'closed_at' => now()]);

    // Straight to the stop.
    [$stopped, $stoppedBuy] = followedBuy();
    $trading->execute(exitOf($stoppedBuy, 'stop_loss'), 1.8);
    $stopped->update(['status' => 'stop_loss', 'net_r' => -1.05, 'closed_at' => now()]);

    // Sold with its signal.
    [$expired] = followedBuy();
    $expired->update(['status' => 'expired', 'net_r' => 0.1, 'closed_at' => now()]);
    $trading->closeFinishedSignalLots();

    // Still running.
    followedBuy();

    // Skipped, and its signal lost.
    $skipped = Signal::withoutEvents(fn () => Signal::factory()->for(Token::factory()->create())->create(['recommendation' => 'BUY', 'status' => 'stop_loss', 'net_r' => -1.0, 'closed_at' => now()]));
    AutoFollowDecision::record(User::autoFollower('buy'), 'buy', $skipped, AutoFollowDecision::SKIPPED, AutoFollowDecision::POSITION_LIMIT);

    $this->getJson('/api/reports/accounts/buy/report')->assertUnauthorized();

    $report = $this->actingAs($this->person)->getJson('/api/reports/accounts/buy/report?version=all')->assertOk()->json();
    $lots = collect($report['lots'])->keyBy('signal.id');

    expect($lots[$breakeven->id]['exit'])->toBe('breakeven')
        ->and($lots[$breakeven->id]['targets_hit'])->toBe(1)
        ->and($lots[$breakeven->id]['pnl_usd'])->toBeGreaterThan(0)
        ->and($lots[$stopped->id]['exit'])->toBe('stop_loss')
        ->and($lots[$stopped->id]['r'])->toBeLessThan(-0.95)->toBeGreaterThan(-1.1)
        ->and($lots[$expired->id]['exit'])->toBe('signal_closed')
        ->and($lots->where('status', 'open')->count())->toBe(1)
        ->and($report['summary']['lots'])->toBe(3)
        ->and($report['summary']['signal_total_r'])->toEqual(-0.55)
        ->and($report['criteria'][0]['value'])->toBe('3')
        ->and($report['criteria'][0]['passed'])->toBeFalse()
        ->and(collect($report['exits'])->pluck('exit')->all())->toBe(['breakeven', 'stop_loss', 'signal_closed'])
        ->and($report['funnel'])->toMatchArray([
            'signals' => 5,
            'bought' => 4,
            'filled' => 4,
            'skipped' => 1,
            'skipped_by_reason' => ['position_limit' => 1],
            'unknown' => 0,
            'open_lots' => 1,
            'closed_lots' => 3,
            'missed_signals' => 1,
            'missed_r' => -1,
        ])
        ->and($report['weeks'])->toHaveCount(1);

    $this->getJson('/api/reports/accounts/nope/report')->assertNotFound();
});

test('a lot shows its levels and every fill for the chart, and only for the auto-follow accounts', function () {
    $trading = app(TradingService::class);
    [$signal, $buy] = followedBuy();
    $trading->execute(exitOf($buy, 'take_profit'), 2.3);

    $this->actingAs($this->person)->getJson("/api/reports/lots/{$buy->id}")->assertOk()
        ->assertJsonPath('account.key', 'buy')
        ->assertJsonPath('signal.id', $signal->id)
        ->assertJsonPath('signal.take_profits', [2.3, 2.5, 2.8])
        ->assertJsonCount(2, 'fills')
        ->assertJsonPath('fills.0.side', 'buy')
        ->assertJsonPath('fills.1.type', 'take_profit')
        // The stop moved to the entry after the first target.
        ->assertJsonPath('stop', (float) $buy->filled_price);

    [$own] = $trading->followSignal($this->person, $signal, 100);

    $this->getJson("/api/reports/lots/{$own->id}")->assertNotFound();
    $this->getJson('/api/reports/lots/'.exitOf($buy, 'take_profit')->id)->assertNotFound();
});

test('each decision of an auto-follow account is recorded, with the reason for a skip', function () {
    app(SettingsStore::class)->set(['market.autofollow.max_open_positions' => 1]);

    [$first] = followedBuy();
    $second = Signal::factory()->for(Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]))
        ->active(2.0)->create(['recommendation' => 'BUY', 'entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);
    $account = User::autoFollower('buy');

    expect(AutoFollowDecision::where('user_id', $account->id)->where('signal_id', $first->id)->sole()->decision)->toBe('bought')
        ->and(AutoFollowDecision::where('user_id', $account->id)->where('signal_id', $second->id)->sole())
        ->decision->toBe('skipped')
        ->reason->toBe('position_limit');
});

test('signals that have not entered their zone are counted apart, not as missing a decision', function () {
    $make = fn () => Signal::factory()->for(Token::factory()->create(['price_usd' => 2.0, 'price_updated_at' => now()]))
        ->create(['recommendation' => 'BUY', 'entry_low' => 1.85, 'entry_high' => 1.9, 'stop_loss' => 1.7]);
    followedBuy();
    $make();
    $make()->update(['status' => 'expired', 'closed_at' => now()]);

    $funnel = $this->actingAs($this->person)->getJson('/api/reports/accounts/buy/report?version=all')->assertOk()->json('funnel');

    expect($funnel)->toMatchArray(['signals' => 3, 'bought' => 1, 'awaiting_entry' => 1, 'never_entered' => 1, 'unknown' => 0]);
});

test('the decision history is filled from buy orders and from the log, once', function () {
    $signals = Signal::withoutEvents(fn () => Signal::factory()->count(4)->for(Token::factory()->create())->create());
    Order::factory()->for(User::autoFollower('all'), 'user')->for($signals[3]->token)->create(['signal_id' => $signals[3]->id, 'side' => 'buy', 'status' => OrderStatus::Filled]);

    $dir = storage_path('framework/testing/decision-logs');
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/laravel-2026-10-31.log", implode("\n", [
        "[2026-10-31 08:44:33] production.INFO: Auto-follow melewati sinyal #{$signals[0]->id}: batas posisi terbuka tercapai.  ",
        "[2026-11-07 03:26:05] production.INFO: Auto-follow strong_buy melewati sinyal #{$signals[1]->id}: batas rugi harian tercapai.  ",
        "[2026-11-07 03:27:05] production.INFO: Auto-follow buy melewati sinyal #{$signals[2]->id}: Harga sudah di bawah stop loss sinyal.",
        // The buy wins over a skip logged before a retry bought it.
        "[2026-11-07 03:28:05] production.INFO: Auto-follow all melewati sinyal #{$signals[3]->id}: batas posisi terbuka tercapai.  ",
        '[2026-11-07 03:29:05] production.INFO: Auto-follow all melewati sinyal #999999: batas posisi terbuka tercapai.  ',
        '[2026-11-07 03:30:05] production.ERROR: something else',
    ]));

    $this->artisan('market:backfill-decisions', ['--path' => $dir])->assertSuccessful();
    $this->artisan('market:backfill-decisions', ['--path' => $dir])->assertSuccessful();

    $rows = AutoFollowDecision::orderBy('signal_id')->get()->keyBy('signal_id');

    expect($rows)->toHaveCount(4)
        ->and($rows[$signals[0]->id])->account->toBe('all')->reason->toBe('position_limit')->source->toBe('backfill')
        ->and($rows[$signals[0]->id]->decided_at->toDateTimeString())->toBe('2026-10-31 08:44:33')
        ->and($rows[$signals[1]->id])->account->toBe('strong_buy')->reason->toBe('daily_loss')
        ->and($rows[$signals[2]->id])->reason->toBe('rejected')->message->toBe('Harga sudah di bawah stop loss sinyal.')
        ->and($rows[$signals[3]->id]->decision)->toBe('bought');

    File::deleteDirectory($dir);
});

test('a chain account report only counts signals on its own chain', function () {
    followedBuy();
    $bsc = Token::factory()->create(['chain_id' => 'bsc', 'price_usd' => 2.0, 'price_updated_at' => now()]);
    Signal::factory()->for($bsc)->active(2.0)->create(['recommendation' => 'BUY', 'entry_low' => 1.95, 'entry_high' => 2.05, 'stop_loss' => 1.8]);

    $report = $this->actingAs($this->person)->getJson('/api/reports/accounts/solana/report?version=all')->assertOk()->json();

    expect($report['chains'])->toBe(['solana'])
        ->and($report['funnel'])->toMatchArray(['signals' => 1, 'bought' => 1, 'unknown' => 0])
        ->and($this->getJson('/api/reports/accounts/bsc/report?version=all')->json('funnel.signals'))->toBe(1);
});
