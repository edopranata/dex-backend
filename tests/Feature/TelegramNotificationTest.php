<?php

use App\Enums\Recommendation;
use App\Enums\WatchlistAction;
use App\Models\Analysis;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Models\WatchlistEvent;
use App\Services\Admin\SettingsStore;
use App\Services\Analysis\SignalTracker;
use App\Services\Notifications\TelegramClient;
use App\Services\Notifications\TelegramException;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Saved like the admin panel does, because queued jobs re-apply stored settings before running.
 *
 * @param  array<string, mixed>  $values
 */
function telegramSettings(array $values): void
{
    app(SettingsStore::class)->set($values);
}

function fakeTelegram(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
}

beforeEach(function () {
    config(['app.url' => 'https://dexsim.test']);

    telegramSettings([
        'market.telegram.enabled' => true,
        'market.telegram.bot_token' => '123:secret-token',
        'market.telegram.chat_id' => '42',
        'market.telegram.signal_min' => 'BUY',
        'market.telegram.notify.signals' => true,
        'market.telegram.notify.signal_updates' => true,
        'market.telegram.notify.risk' => true,
        'market.telegram.notify.watchlist' => true,
        'market.telegram.notify.system' => true,
        'market.telegram.notify.daily_summary' => true,
        'market.telegram.daily_summary_hour' => 21,
        'market.telegram.quiet_start_hour' => 0,
        'market.telegram.quiet_end_hour' => 0,
    ]);
});

/**
 * @return list<string>
 */
function telegramTexts(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/sendMessage'))
        ->map(fn (array $pair): string => $pair[0]['text'])
        ->values()
        ->all();
}

class ExplodingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

test('a new buy signal sends entry, stop loss and all targets with a link', function () {
    fakeTelegram();
    $token = Token::factory()->create(['symbol' => 'PEPE', 'price_usd' => 0.00001234]);
    $signal = Signal::factory()->for($token)->create([
        'recommendation' => Recommendation::StrongBuy,
        'entry_low' => 0.0000118, 'entry_high' => 0.0000120, 'stop_loss' => 0.0000110,
        'take_profit_1' => 0.0000135, 'take_profit_2' => 0.0000145, 'take_profit_3' => 0.0000160,
    ]);

    $text = telegramTexts()[0];

    expect($text)->toContain('Sinyal Strong Buy: PEPE')
        ->toContain('Entry: $0.0000118 – $0.000012')
        ->toContain('Stop loss: $0.000011 (-8.3%)')
        ->toContain('TP3: $0.000016 (+33.3%)')
        ->toContain("https://dexsim.test/tokens/{$token->id}");

    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '42' && $request['parse_mode'] === 'HTML' && $request['disable_notification'] === false);
});

test('nothing is sent when telegram is disabled, the type is off, or the signal is below the filter', function () {
    fakeTelegram();
    telegramSettings(['market.telegram.enabled' => false]);
    Signal::factory()->create();

    telegramSettings(['market.telegram.enabled' => true, 'market.telegram.notify.signals' => false]);
    Signal::factory()->create();

    telegramSettings(['market.telegram.notify.signals' => true, 'market.telegram.signal_min' => 'STRONG_BUY']);
    Signal::factory()->create(['recommendation' => Recommendation::Buy]);

    Http::assertNothingSent();
});

test('the tracker reports entry, a target and the stop at breakeven as separate messages', function () {
    fakeTelegram();
    $signal = Signal::factory()->create();
    $tracker = app(SignalTracker::class);

    $tracker->track($signal, 0.99);
    $tracker->track($signal->refresh(), 1.07);
    $tracker->track($signal->refresh(), 0.98);

    $texts = telegramTexts();

    expect($texts)->toHaveCount(4)
        ->and($texts[1])->toContain('masuk zona entry di $0.99')
        ->and($texts[2])->toContain('TP1 tercapai ($1.06)')->toContain('breakeven')
        ->and($texts[3])->toContain('ditutup di breakeven setelah TP1')->toContain('+0.58R');
});

test('a stop loss rings even during quiet hours while other updates stay silent', function () {
    fakeTelegram();
    telegramSettings(['market.telegram.quiet_start_hour' => 0, 'market.telegram.quiet_end_hour' => 23]);
    Carbon::setTestNow(Carbon::parse('2026-10-29 10:00', 'Asia/Jakarta'));

    $signal = Signal::factory()->active(1.0)->create();
    app(SignalTracker::class)->track($signal, 0.94);

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

    expect($requests[0]['disable_notification'])->toBeTrue()
        ->and($requests[1]['text'])->toContain('kena stop loss')->toContain('-1.00R')
        ->and($requests[1]['disable_notification'])->toBeFalse();
});

test('a cancelled pending signal tells the user not to enter', function () {
    fakeTelegram();
    $signal = Signal::factory()->create();

    app(SignalTracker::class)->track($signal, 0.90);

    expect(telegramTexts()[1])->toContain('batal')->toContain('Jangan entry');
});

test('tokens entering and leaving the market are announced, pins are not', function () {
    fakeTelegram();
    $token = Token::factory()->create(['symbol' => 'WIF']);

    WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Added, 'reason' => 'Skor discovery tinggi.', 'score' => 81]);
    WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Removed, 'reason' => 'Skor turun 15 poin.', 'score' => 40]);
    WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Pinned, 'reason' => 'Dikunci user.']);

    $texts = telegramTexts();

    expect($texts)->toHaveCount(2)
        ->and($texts[0])->toContain('WIF')->toContain('masuk Market (skor 81)')
        ->and($texts[1])->toContain('keluar dari Market')->toContain('Skor turun 15 poin.');
});

test('risk alerts repeat at most once per 12 hours per token', function () {
    fakeTelegram();
    $token = Token::factory()->create();
    $notifier = app(TelegramNotifier::class);

    $notifier->riskAlert($token, ['PERINGATAN: likuiditas turun 60% dalam 24 jam (indikasi rug pull).']);
    $notifier->riskAlert($token, ['PERINGATAN: likuiditas turun 70% dalam 24 jam (indikasi rug pull).']);

    expect(telegramTexts())->toHaveCount(1)
        ->and(telegramTexts()[0])->toContain('Peringatan risiko')->toContain('60%');
});

test('a failed job raises one operational alert per window', function () {
    fakeTelegram();
    foreach ([1, 2] as $attempt) {
        try {
            dispatch(new ExplodingJob);
        } catch (RuntimeException) {
        }
    }

    expect(telegramTexts())->toHaveCount(1)
        ->and(telegramTexts()[0])->toContain('Job gagal: ExplodingJob')->toContain('boom');
});

test('the bot token never appears in error messages', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot123:secret-token/sendMessage'));

    expect(fn () => app(TelegramClient::class)->send('hi'))
        ->toThrow(fn (TelegramException $e) => expect($e->getMessage())->not->toContain('secret-token')->toContain('***'));
});

test('admins can find the chat id and send a test message', function () {
    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'dexsim_bot', 'first_name' => 'DEX Sim']]),
        'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'message' => ['chat' => ['id' => 42, 'type' => 'private', 'first_name' => 'Edo']]],
            ['update_id' => 2, 'message' => ['chat' => ['id' => -100, 'type' => 'group', 'title' => 'Trading']]],
            ['update_id' => 3, 'message' => ['chat' => ['id' => 42, 'type' => 'private', 'first_name' => 'Edo']]],
        ]]),
        'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => []]),
    ]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->postJson('/api/admin/telegram/chats')
        ->assertOk()
        ->assertJsonPath('bot.username', 'dexsim_bot')
        ->assertJsonPath('chats', [
            ['id' => '42', 'type' => 'private', 'title' => 'Edo'],
            ['id' => '-100', 'type' => 'group', 'title' => 'Trading'],
        ]);

    $this->actingAs($admin)->postJson('/api/admin/telegram/test')->assertOk()->assertJsonPath('ok', true);

    $this->actingAs(User::factory()->create(['is_admin' => false]))->postJson('/api/admin/telegram/test')->assertForbidden();
});

test('a rejected test reports the telegram error', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->postJson('/api/admin/telegram/test')
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error', 'Telegram menolak: Unauthorized');
});

test('the daily summary is sent only in the configured hour', function () {
    fakeTelegram();
    $token = Token::factory()->create(['symbol' => 'BONK']);
    Signal::factory()->for($token)->create(['status' => 'take_profit', 'result_r' => 1.5, 'closed_at' => now()]);
    Analysis::factory()->for($token)->create(['final_score' => 77, 'recommendation' => Recommendation::StrongBuy]);

    Carbon::setTestNow(Carbon::parse('2026-10-29 20:00', 'Asia/Jakarta'));
    $this->artisan('app:telegram-daily-summary')->assertSuccessful();
    $before = count(telegramTexts());

    Carbon::setTestNow(Carbon::parse('2026-10-29 21:00', 'Asia/Jakarta'));
    $this->artisan('app:telegram-daily-summary')->assertSuccessful();

    $summary = last(telegramTexts());

    expect(count(telegramTexts()))->toBe($before + 1)
        ->and($summary)->toContain('Ringkasan 24 jam')->toContain('menang 1')->toContain('+1.50R')->toContain('BONK <code>solana</code> 77 (Strong Buy)');
});

test('the notify command sends operational alerts immediately', function () {
    fakeTelegram();
    $this->artisan('app:telegram-notify', ['title' => 'Backup database gagal', 'detail' => 'baris 20'])->assertSuccessful();

    expect(telegramTexts()[0])->toContain('Backup database gagal')->toContain('baris 20');
});
