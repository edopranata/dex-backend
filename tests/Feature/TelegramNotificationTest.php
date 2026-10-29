<?php

use App\Enums\Recommendation;
use App\Enums\WatchlistAction;
use App\Models\Analysis;
use App\Models\Position;
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

/**
 * @param  array<string, mixed>  $preferences
 */
function linkedUser(string $chatId = '42', array $preferences = [], bool $admin = false): User
{
    return User::factory()->create([
        'telegram_chat_id' => $chatId,
        'telegram_linked_at' => now(),
        'is_admin' => $admin,
        'notification_preferences' => [...$preferences, 'quiet_start_hour' => 0, 'quiet_end_hour' => 0, ...$preferences],
    ]);
}

/**
 * @return list<array{chat: string, text: string, silent: bool}>
 */
function telegramMessages(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/sendMessage'))
        ->map(fn (array $pair): array => ['chat' => (string) $pair[0]['chat_id'], 'text' => $pair[0]['text'], 'silent' => $pair[0]['disable_notification']])
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

beforeEach(function () {
    config(['app.url' => 'https://dexsim.test']);
    telegramSettings(['market.telegram.enabled' => true, 'market.telegram.bot_token' => '123:secret-token']);
});

test('a new buy signal goes to linked users only, with entry, stop loss, targets and a link', function () {
    fakeTelegram();
    linkedUser('42');
    User::factory()->create();
    $token = Token::factory()->create(['symbol' => 'PEPE', 'price_usd' => 0.00001234]);

    Signal::factory()->for($token)->create([
        'recommendation' => Recommendation::StrongBuy,
        'entry_low' => 0.0000118, 'entry_high' => 0.0000120, 'stop_loss' => 0.0000110,
        'take_profit_1' => 0.0000135, 'take_profit_2' => 0.0000145, 'take_profit_3' => 0.0000160,
    ]);

    $messages = telegramMessages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat'])->toBe('42')
        ->and($messages[0]['silent'])->toBeFalse()
        ->and($messages[0]['text'])->toContain('Sinyal Strong Buy: PEPE')
        ->toContain('Entry: $0.0000118 – $0.000012')
        ->toContain('Stop loss: $0.000011 (-8.3%)')
        ->toContain('TP3: $0.000016 (+33.3%)')
        ->toContain("https://dexsim.test/tokens/{$token->id}");
});

test('each user filter decides who gets a signal', function () {
    fakeTelegram();
    linkedUser('1', ['signal_min' => 'STRONG_BUY']);
    linkedUser('2', ['signals' => false]);
    linkedUser('3');

    Signal::factory()->create(['recommendation' => Recommendation::Buy]);

    expect(array_column(telegramMessages(), 'chat'))->toBe(['3']);
});

test('nothing is sent while telegram is switched off by the admin', function () {
    fakeTelegram();
    linkedUser();
    telegramSettings(['market.telegram.enabled' => false]);

    Signal::factory()->create();

    Http::assertNothingSent();
});

test('the tracker reports entry, a target and the stop at breakeven as separate messages', function () {
    fakeTelegram();
    linkedUser();
    $signal = Signal::factory()->create();
    $tracker = app(SignalTracker::class);

    $tracker->track($signal, 0.99);
    $tracker->track($signal->refresh(), 1.07);
    $tracker->track($signal->refresh(), 0.98);

    $texts = array_column(telegramMessages(), 'text');

    expect($texts)->toHaveCount(4)
        ->and($texts[1])->toContain('masuk zona entry di $0.99')
        ->and($texts[2])->toContain('TP1 tercapai ($1.06)')->toContain('breakeven')
        ->and($texts[3])->toContain('ditutup di breakeven setelah TP1')->toContain('+0.58R');
});

test('a stop loss rings during the user quiet hours while other updates stay silent', function () {
    fakeTelegram();
    linkedUser('42', ['quiet_start_hour' => 0, 'quiet_end_hour' => 23]);
    Carbon::setTestNow(Carbon::parse('2026-10-29 10:00', 'Asia/Jakarta'));

    $signal = Signal::factory()->active(1.0)->create();
    app(SignalTracker::class)->track($signal, 0.94);

    $messages = telegramMessages();

    expect($messages[0]['silent'])->toBeTrue()
        ->and($messages[1]['text'])->toContain('kena stop loss')->toContain('-1.00R')
        ->and($messages[1]['silent'])->toBeFalse();
});

test('a cancelled pending signal tells the user not to enter', function () {
    fakeTelegram();
    linkedUser();
    $signal = Signal::factory()->create();

    app(SignalTracker::class)->track($signal, 0.90);

    expect(telegramMessages()[1]['text'])->toContain('batal')->toContain('Jangan entry');
});

test('market additions and removals reach users who opted in, pins do not', function () {
    fakeTelegram();
    linkedUser('1', ['watchlist' => true]);
    linkedUser('2');
    $token = Token::factory()->create(['symbol' => 'WIF']);

    WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Added, 'reason' => 'Skor discovery tinggi.', 'score' => 81]);
    WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Removed, 'reason' => 'Skor turun 15 poin.', 'score' => 40]);
    WatchlistEvent::factory()->for($token)->create(['action' => WatchlistAction::Pinned, 'reason' => 'Dikunci user.']);

    $messages = telegramMessages();

    expect(array_column($messages, 'chat'))->toBe(['1', '1'])
        ->and($messages[0]['text'])->toContain('WIF')->toContain('masuk Market (skor 81)')
        ->and($messages[1]['text'])->toContain('keluar dari Market')->toContain('Skor turun 15 poin.');
});

test('risk alerts mention open positions to holders only and repeat at most every 12 hours', function () {
    fakeTelegram();
    $holder = linkedUser('1');
    linkedUser('2');
    $token = Token::factory()->create();
    Position::factory()->for($holder)->for($token)->create(['quantity' => 10]);
    $notifier = app(TelegramNotifier::class);

    $notifier->riskAlert($token, ['PERINGATAN: likuiditas turun 60% dalam 24 jam (indikasi rug pull).']);
    $notifier->riskAlert($token, ['PERINGATAN: likuiditas turun 70% dalam 24 jam (indikasi rug pull).']);

    $messages = collect(telegramMessages())->keyBy('chat');

    expect($messages)->toHaveCount(2)
        ->and($messages['1']['text'])->toContain('60%')->toContain('Anda punya posisi terbuka')
        ->and($messages['2']['text'])->not->toContain('posisi terbuka');
});

test('a failed job alerts admins once per window', function () {
    fakeTelegram();
    linkedUser('1', admin: true);
    linkedUser('2');

    foreach ([1, 2] as $attempt) {
        try {
            dispatch(new ExplodingJob);
        } catch (RuntimeException) {
        }
    }

    $messages = telegramMessages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat'])->toBe('1')
        ->and($messages[0]['text'])->toContain('Job gagal: ExplodingJob')->toContain('boom');
});

test('the bot token never appears in error messages', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot123:secret-token/sendMessage'));

    expect(fn () => app(TelegramClient::class)->send('42', 'hi'))
        ->toThrow(fn (TelegramException $e) => expect($e->getMessage())->not->toContain('secret-token')->toContain('***'));
});

test('daily summaries go out in each user own hour with their portfolio', function () {
    fakeTelegram();
    $user = linkedUser('1', ['daily_summary_hour' => 21]);
    linkedUser('2', ['daily_summary_hour' => 8]);
    $token = Token::factory()->create(['symbol' => 'BONK']);
    Signal::factory()->for($token)->create(['status' => 'take_profit', 'result_r' => 1.5, 'closed_at' => now()]);
    Analysis::factory()->for($token)->create(['final_score' => 77, 'recommendation' => Recommendation::StrongBuy]);

    Carbon::setTestNow(Carbon::parse('2026-10-29 21:00', 'Asia/Jakarta'));
    $this->artisan('app:telegram-daily-summary')->assertSuccessful();

    $messages = array_values(array_filter(telegramMessages(), fn (array $m): bool => str_contains($m['text'], 'Ringkasan 24 jam')));

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat'])->toBe('1')
        ->and($messages[0]['text'])->toContain('Ekuitas: <b>$'.number_format($user->balance_usd, 2))
        ->toContain('menang 1')->toContain('+1.50R')->toContain('BONK <code>solana</code> 77 (Strong Buy)');
});

test('the notify command sends operational alerts to admins immediately', function () {
    fakeTelegram();
    linkedUser('1', admin: true);

    $this->artisan('app:telegram-notify', ['title' => 'Backup database gagal', 'detail' => 'baris 20'])->assertSuccessful();

    expect(telegramMessages()[0]['text'])->toContain('Backup database gagal')->toContain('baris 20');
});
