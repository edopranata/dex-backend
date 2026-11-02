<?php

use App\Enums\Recommendation;
use App\Enums\SignalStatus;
use App\Jobs\AnalyzeToken;
use App\Models\Analysis;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Notifications\TelegramClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['app.url' => 'https://dexsim.test']);
    app(SettingsStore::class)->set(['market.telegram.enabled' => true, 'market.telegram.bot_token' => '123:secret-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]])]);
    User::factory()->create(['telegram_chat_id' => '777']);
});

function marketToken(string $symbol, Recommendation $recommendation, int $score): Token
{
    $token = Token::factory()->create(['symbol' => $symbol, 'chain_id' => 'base', 'price_usd' => 1.5, 'price_change_h24' => 2.5]);
    Analysis::factory()->for($token)->create(['recommendation' => $recommendation, 'final_score' => $score]);

    return $token;
}

function marketCommand(string $text, int $chatId = 777)
{
    return test()->postJson('/api/telegram/webhook', [
        'update_id' => 1,
        'message' => ['chat' => ['id' => $chatId, 'type' => 'private'], 'text' => $text],
    ], ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramClient::class)->webhookSecret()]);
}

function marketTap(string $data, string $chatId = '777')
{
    return test()->postJson('/api/telegram/webhook', [
        'update_id' => 2,
        'callback_query' => ['id' => 'cb1', 'data' => $data, 'message' => ['message_id' => 50, 'chat' => ['id' => (int) $chatId]]],
    ], ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramClient::class)->webhookSecret()]);
}

test('/market asks what to show, with every filter as a button', function () {
    $response = marketCommand('/market')->assertOk();

    expect($response->json('text'))->toContain('Pilih yang ingin ditampilkan')
        ->and(collect($response->json('reply_markup.inline_keyboard'))->flatten(1)->pluck('callback_data')->all())
        ->toBe(['mkt:strong_buy', 'mkt:buy', 'mkt:hold', 'mkt:sell', 'mkt:signals', 'mkt:all']);
});

test('a filter shows only its tokens, highest score first', function (string $filter, array $expected, array $hidden) {
    marketToken('STRONGLOW', Recommendation::StrongBuy, 70);
    marketToken('STRONGHIGH', Recommendation::StrongBuy, 90);
    marketToken('BUYONE', Recommendation::Buy, 65);
    marketToken('HOLDONE', Recommendation::Hold, 50);
    marketToken('SELLONE', Recommendation::Sell, 30);
    marketToken('AVOIDONE', Recommendation::Avoid, 10);

    $text = marketCommand("/market {$filter}")->assertOk()->json('text');

    expect($text)->toContain(...$expected);

    foreach ($hidden as $symbol) {
        expect($text)->not->toContain($symbol);
    }
})->with([
    'strong_buy' => ['strong_buy', ['STRONGHIGH', 'STRONGLOW'], ['BUYONE', 'HOLDONE', 'SELLONE']],
    'buy' => ['buy', ['BUYONE'], ['STRONGHIGH', 'HOLDONE']],
    'hold' => ['hold', ['HOLDONE'], ['BUYONE', 'SELLONE']],
    'sell and avoid' => ['sell', ['SELLONE', 'AVOIDONE'], ['HOLDONE', 'STRONGHIGH']],
    'all' => ['all', ['STRONGHIGH', 'BUYONE', 'HOLDONE', 'AVOIDONE'], []],
]);

test('tokens are sorted by score and a line links to the token', function () {
    $low = marketToken('LOWSCORE', Recommendation::StrongBuy, 70);
    marketToken('HIGHSCORE', Recommendation::StrongBuy, 90);

    $text = marketCommand('/market strong_buy')->json('text');

    expect(strpos($text, 'HIGHSCORE'))->toBeLessThan(strpos($text, 'LOWSCORE'))
        ->and($text)->toContain("https://dexsim.test/tokens/{$low->id}")->toContain('skor 70')->toContain('+2.5%');
});

test('the open signals filter lists tokens with a signal still running', function () {
    $open = marketToken('OPENED', Recommendation::Hold, 50);
    $closed = marketToken('CLOSED', Recommendation::Hold, 50);
    Signal::factory()->for($open)->create(['status' => SignalStatus::Active]);
    Signal::factory()->for($closed)->create(['status' => SignalStatus::StopLoss]);

    $text = marketCommand('/market signals')->json('text');

    expect($text)->toContain('OPENED')->toContain('📍')->not->toContain('CLOSED');
});

test('a token outside the market and a symbol with markup are handled', function () {
    $gone = marketToken('GONE', Recommendation::Buy, 80);
    $gone->update(['is_active' => false]);
    marketToken('A<B', Recommendation::Buy, 60);

    $text = marketCommand('/market buy')->json('text');

    expect($text)->not->toContain('GONE')->toContain('A&lt;B');
});

test('an empty filter says so and an unknown one shows the choices', function () {
    expect(marketCommand('/market buy')->json('text'))->toContain('Tidak ada token')
        ->and(marketCommand('/market nonsense')->json('text'))->toContain('Pilih yang ingin');
});

test('tapping a filter replaces the message and keeps the buttons', function () {
    marketToken('BUYONE', Recommendation::Buy, 65);

    marketTap('mkt:buy')->assertNoContent();

    $edit = Http::recorded()->map(fn (array $pair): Request => $pair[0])->first(fn (Request $r): bool => str_ends_with($r->url(), '/editMessageText'));

    expect($edit['message_id'])->toBe(50)
        ->and($edit['text'])->toContain('BUYONE')
        ->and($edit['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe('mkt:strong_buy');
});

test('an unlinked chat sees no market data', function () {
    marketToken('BUYONE', Recommendation::Buy, 65);

    expect(marketCommand('/market buy', 999)->json('text'))->toContain('belum terhubung')->not->toContain('BUYONE');

    marketTap('mkt:buy', '999')->assertNoContent();

    Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/editMessageText'));
});

test('the market list asks no provider and queues no analysis', function () {
    Queue::fake();
    marketToken('BUYONE', Recommendation::Buy, 65);
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    marketCommand('/market all')->assertOk();

    Queue::assertNotPushed(AnalyzeToken::class);
    Http::assertNothingSent();
});
