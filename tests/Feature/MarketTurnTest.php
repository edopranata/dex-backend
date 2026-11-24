<?php

use App\Jobs\SendTelegramMessage;
use App\Models\Analysis;
use App\Models\ShadowSignal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 01:05:00', 'UTC'));
    Queue::fake();
    app(SettingsStore::class)->set(['market.telegram.enabled' => true, 'market.telegram.bot_token' => '123:secret-token']);
    User::factory()->create(['is_admin' => true, 'telegram_chat_id' => '777']);
});

/**
 * 80 closed daily BTC candles flat at 100, then `rising` days climbing above the SMA50, plus today's open candle.
 * Fear & Greed: the last `greedy` days at 60, earlier ones at 30.
 */
function fakeMarket(int $rising, int $greedy): void
{
    $today = CarbonImmutable::parse('2026-12-20', 'UTC');
    $rows = [];
    for ($d = 80; $d >= 0; $d--) {
        $close = $d === 0 ? 1.0 : ($d <= $rising ? 100 + (100 - $d) : 100.0);   // today (d=0) must be ignored
        $rows[] = [(string) ($today->subDays($d)->getTimestamp() * 1000), '0', '0', '0', (string) $close, '0', '0'];
    }
    $fng = [];
    for ($d = 0; $d < 30; $d++) {
        $fng[] = ['value' => $d < $greedy ? '60' : '30', 'timestamp' => (string) $today->subDays($d)->getTimestamp()];
    }
    Http::fake([
        'api.bybit.id/*' => Http::response(['retCode' => 0, 'result' => ['list' => array_reverse($rows)]]),
        'api.alternative.me/*' => Http::response(['data' => $fng]),
    ]);
}

test('the command reports each trigger with its progress', function () {
    fakeMarket(rising: 5, greedy: 3);

    $this->artisan('app:market-turn-check --dry')
        ->expectsOutputToContain('BTC harian di atas SMA50                         5/14')
        ->expectsOutputToContain('3/14')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('a trigger that is newly met sends one Telegram message, and not again the next day', function () {
    fakeMarket(rising: 14, greedy: 3);

    $this->artisan('app:market-turn-check')->expectsOutputToContain('TERPENUHI')->assertSuccessful();
    Queue::assertPushed(SendTelegramMessage::class, 1);

    $this->travel(1)->days();
    $this->artisan('app:market-turn-check')->assertSuccessful();
    Queue::assertPushed(SendTelegramMessage::class, 1);
});

test('most DEX analyses with a bullish BTC trend for 7 days, and 30 finished Buy shadows in it, are triggers too', function () {
    fakeMarket(rising: 0, greedy: 0);
    $token = Token::factory()->create();
    $context = fn (string $trend): array => ['benchmarks' => ['BTC' => ['trend_4h' => $trend]]];
    foreach (range(1, 7) as $d) {
        $at = CarbonImmutable::parse('2026-12-20 10:00', 'UTC')->subDays($d);
        foreach (['bullish', 'bullish', 'sideways'] as $trend) {
            Analysis::factory()->for($token)->create(['market_context' => $context($trend), 'created_at' => $at]);
        }
    }
    $analysis = Analysis::factory()->for($token)->create(['market_context' => $context('bullish')]);
    foreach (range(1, 30) as $n) {
        ShadowSignal::query()->create(['token_id' => $token->id, 'analysis_id' => $analysis->id, 'rule' => ShadowSignal::BUY_WATCH, 'recommendation' => 'BUY',
            'strategy_version' => 'v7-test', 'metric' => 1, 'entry_low' => 1, 'entry_high' => 1, 'stop_loss' => 0.9, 'take_profit_1' => 1.1,
            'take_profit_2' => 1.2, 'take_profit_3' => 1.3, 'status' => 'stop_loss', 'net_r' => -1.0]);
    }

    $this->artisan('app:market-turn-check --dry')
        ->expectsOutputToContain('7/7')
        ->expectsOutputToContain('30/30')
        ->assertSuccessful();
});
