<?php

use App\Models\EquitySnapshot;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Analysis\StrategyVersion;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = Token::factory()->create(['symbol' => 'PEPE']);
});

function reportSignal(Token $token, float $netR, string $closedAt, array $attributes = []): Signal
{
    return Signal::withoutEvents(fn () => Signal::factory()->for($token)->create([
        'status' => $netR > 0 ? 'take_profit' : 'stop_loss',
        'recommendation' => 'BUY',
        'entry_price' => 1,
        'exit_price' => 1,
        'net_r' => $netR,
        'result_r' => $netR + 0.1,
        'cost_r' => 0.1,
        'strategy_version' => 'v2-abc',
        'closed_at' => $closedAt,
        ...$attributes,
    ]));
}

test('the report needs a login', function () {
    $this->getJson('/api/reports/performance')->assertUnauthorized();
});

test('results are grouped per local week, recommendation and token, for one strategy version', function () {
    reportSignal($this->token, 2.0, '2026-10-26 02:00:00');
    reportSignal($this->token, -1.0, '2026-10-27 02:00:00', ['recommendation' => 'STRONG_BUY']);
    // Sunday 18:00 UTC is already Monday in Jakarta, so it belongs to the next week.
    reportSignal(Token::factory()->create(['symbol' => 'BONK']), 1.0, '2026-11-01 18:00:00');
    reportSignal($this->token, 5.0, '2026-10-26 02:00:00', ['strategy_version' => 'v0-old']);
    Signal::withoutEvents(fn () => Signal::factory()->for($this->token)->create(['status' => 'expired', 'strategy_version' => 'v2-abc', 'closed_at' => now()]));

    $response = $this->actingAs($this->user)->getJson('/api/reports/performance?version=v2-abc')
        ->assertOk()
        ->assertJsonPath('summary.trades', 3)
        ->assertJsonPath('summary.profit_factor', 3)
        ->assertJsonCount(2, 'weeks')
        ->assertJsonPath('weeks.0.week_start', '2026-11-02')
        ->assertJsonPath('weeks.1.week_start', '2026-10-26')
        ->assertJsonPath('tokens.0.symbol', 'PEPE')
        ->assertJsonPath('tokens.1.symbol', 'BONK');

    expect($response->json('weeks.0.cumulative_r'))->toEqual(2.0)
        ->and(collect($response->json('recommendations'))->pluck('recommendation')->sort()->values()->all())->toBe(['BUY', 'STRONG_BUY'])
        ->and($response->json('versions'))->toContain('v2-abc', 'v0-old');

    $this->actingAs($this->user)->getJson('/api/reports/performance?version=all')->assertJsonPath('summary.trades', 4);
});

test('the fase 2 criteria do not pass on a good average from too few signals', function () {
    foreach (range(1, 5) as $day) {
        reportSignal($this->token, 2.0, "2026-10-0{$day} 02:00:00");
    }

    $criteria = $this->actingAs($this->user)->getJson('/api/reports/performance?version=v2-abc')->json('criteria');

    expect(collect($criteria)->pluck('passed')->unique()->all())->toBe([false]);
});

test('the fase 2 criteria pass when every target is met', function () {
    foreach (range(1, 30) as $i) {
        reportSignal($this->token, $i % 3 === 0 ? -1.0 : 1.0, now()->subDays($i)->toDateTimeString());
    }
    config(['market.simulator.starting_balance' => 10_000]);
    EquitySnapshot::query()->create(['user_id' => User::autoFollower()->id, 'equity_usd' => 9500, 'balance_usd' => 9500, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()]);

    $criteria = $this->actingAs($this->user)->getJson('/api/reports/performance?version=v2-abc')->json('criteria');

    expect(collect($criteria)->pluck('passed')->unique()->all())->toBe([true]);
});

test('snapshots build the equity curve and the drawdown from the peak', function () {
    config(['market.simulator.starting_balance' => 10_000]);
    $this->artisan('market:equity-snapshot')->assertSuccessful();
    $bot = User::autoFollower();

    expect(EquitySnapshot::query()->where('user_id', $bot->id)->sole()->equity_usd)->toEqual(10000.0);

    foreach ([11000, 8800, 9500] as $i => $equity) {
        EquitySnapshot::query()->create(['user_id' => $bot->id, 'equity_usd' => $equity, 'balance_usd' => $equity, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()->addHours($i + 1)]);
    }

    $this->actingAs($this->user)->getJson('/api/reports/performance')
        ->assertJsonCount(4, 'auto_follow.curve')
        // From the peak of 11,000 down to 8,800.
        ->assertJsonPath('auto_follow.max_drawdown_pct', 20)
        ->assertJsonPath('auto_follow.curve.3.equity', 9500);
});

test('a long history is thinned for the chart but keeps the latest point', function () {
    $bot = User::autoFollower();
    foreach (range(1, 700) as $i) {
        EquitySnapshot::query()->create(['user_id' => $bot->id, 'equity_usd' => 10000 + $i, 'balance_usd' => 0, 'holdings_usd' => 0, 'open_positions' => 0, 'sampled_at' => now()->subHours(701 - $i)]);
    }

    $curve = $this->actingAs($this->user)->getJson('/api/reports/performance')->json('auto_follow.curve');

    expect(count($curve))->toBeLessThanOrEqual(301)
        ->and(end($curve)['equity'])->toEqual(10700);
});

test('the snapshot runs on schedule', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('market:equity-snapshot');
});

test('the current strategy version can be chosen before it has a signal', function () {
    $this->actingAs($this->user)->getJson('/api/reports/performance')
        ->assertOk()
        ->assertJsonPath('versions.0', StrategyVersion::current())
        ->assertJsonPath('version', StrategyVersion::current())
        ->assertJsonPath('summary.trades', 0);
});
