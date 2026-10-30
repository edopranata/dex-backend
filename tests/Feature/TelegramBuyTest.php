<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Signal;
use App\Models\Token;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use App\Services\Notifications\TelegramBuyFlow;
use App\Services\Notifications\TelegramClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['app.url' => 'https://dexsim.test']);
    app(SettingsStore::class)->set(['market.telegram.enabled' => true, 'market.telegram.bot_token' => '123:secret-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]])]);

    $this->user = User::factory()->create(['balance_usd' => 1000, 'telegram_chat_id' => '777', 'notification_preferences' => ['quiet_start_hour' => 0, 'quiet_end_hour' => 0]]);
    $this->token = Token::factory()->create(['symbol' => 'BRETT', 'chain_id' => 'base', 'price_usd' => 0.00471, 'price_updated_at' => now()]);
    $this->signal = Signal::factory()->for($this->token)->create([
        'entry_low' => 0.00465, 'entry_high' => 0.00472, 'stop_loss' => 0.004486,
        'take_profit_1' => 0.005024, 'take_profit_2' => 0.0053, 'take_profit_3' => 0.0058,
    ]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]])]);
});

function tapButton(string $data, int $messageId = 50, string $chatId = '777')
{
    return test()->postJson('/api/telegram/webhook', [
        'update_id' => random_int(1, 1_000_000),
        'callback_query' => ['id' => 'cb'.random_int(1, 1_000_000), 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => (int) $chatId]]],
    ], ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramClient::class)->webhookSecret()]);
}

function replyText(string $text, string $chatId = '777')
{
    return test()->postJson('/api/telegram/webhook', [
        'update_id' => random_int(1, 1_000_000),
        'message' => ['chat' => ['id' => (int) $chatId, 'type' => 'private'], 'text' => $text],
    ], ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramClient::class)->webhookSecret()]);
}

/**
 * @return list<Request>
 */
function telegramCalls(string $method): array
{
    return Http::recorded()->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $r): bool => str_ends_with($r->url(), "/{$method}"))->values()->all();
}

test('signal messages carry amount buttons', function () {
    $keyboard = TelegramBuyFlow::keyboard($this->signal)['inline_keyboard'];

    expect(array_column($keyboard[0], 'callback_data'))->toBe(["buy:{$this->signal->id}:25", "buy:{$this->signal->id}:50", "buy:{$this->signal->id}:100"])
        ->and($keyboard[1][0]['callback_data'])->toBe("buy:{$this->signal->id}:custom");

    $this->signal->update(['status' => 'active', 'entry_price' => 0.0047, 'entered_at' => now()]);

    $sent = collect(telegramCalls('sendMessage'))->first(fn (Request $r): bool => str_contains($r['text'], 'masuk zona entry'));
    expect($sent['reply_markup']['inline_keyboard'][0][2]['text'])->toBe('$100');
});

test('tapping an amount asks for confirmation and confirming places the limit buy with the signal exits', function () {
    tapButton("buy:{$this->signal->id}:100")->assertNoContent();

    $confirmation = collect(telegramCalls('sendMessage'))->last();
    expect($confirmation['text'])->toContain('Konfirmasi beli BRETT')->toContain('$100.00')->toContain('Limit: $0.00472')
        ->and($confirmation['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe("ok:{$this->signal->id}:100")
        ->and(Order::count())->toBe(0);

    tapButton("ok:{$this->signal->id}:100", messageId: 99)->assertNoContent();

    $buy = Order::where('side', 'buy')->firstOrFail();
    expect($buy->trigger_price)->toEqual(0.00472)
        ->and($buy->status)->toBe(OrderStatus::Filled)
        ->and(Order::where('parent_id', $buy->id)->count())->toBe(4)
        ->and(collect(telegramCalls('editMessageText'))->last()['text'])->toContain("Order #{$buy->id}")->toContain('terisi');

    expect(collect(telegramCalls('answerCallbackQuery'))->count())->toBe(2);
});

test('a double tap on confirm buys only once', function () {
    tapButton("ok:{$this->signal->id}:50", messageId: 99);
    tapButton("ok:{$this->signal->id}:50", messageId: 99);

    expect(Order::where('side', 'buy')->count())->toBe(1)
        ->and(collect(telegramCalls('answerCallbackQuery'))->last()['text'])->toBe('Order ini sudah diproses.');
});

test('a custom amount is typed as a reply and then confirmed', function () {
    tapButton("buy:{$this->signal->id}:custom");

    expect(collect(telegramCalls('sendMessage'))->last()['reply_markup']['force_reply'])->toBeTrue();

    replyText('abc')->assertJsonPath('text', fn (string $t) => str_contains($t, 'Ketik angka'));
    replyText('$150,5')
        ->assertOk()
        ->assertJsonPath('text', fn (string $t) => str_contains($t, 'Konfirmasi beli BRETT') && str_contains($t, '$150.50'))
        ->assertJsonPath('reply_markup.inline_keyboard.0.0.callback_data', "ok:{$this->signal->id}:150.5");

    replyText('/status')->assertJsonPath('text', fn (string $t) => str_contains($t, 'Ekuitas'));
});

test('closed signals, low balances and unlinked chats are refused', function () {
    tapButton("buy:{$this->signal->id}:5000");
    expect(collect(telegramCalls('sendMessage'))->last()['text'])->toContain('Saldo tidak cukup');

    $this->signal->update(['status' => 'stop_loss', 'closed_at' => now()]);
    tapButton("ok:{$this->signal->id}:50", messageId: 99);
    expect(collect(telegramCalls('editMessageText'))->last()['text'])->toContain('sudah ditutup');

    tapButton("buy:{$this->signal->id}:50", chatId: '999');
    expect(collect(telegramCalls('answerCallbackQuery'))->last()['text'])->toContain('belum terhubung')
        ->and(Order::count())->toBe(0);
});

test('cancel removes the confirmation buttons', function () {
    tapButton('no', messageId: 99);

    expect(collect(telegramCalls('editMessageText'))->last()['text'])->toContain('dibatalkan');
});

test('amounts are parsed leniently', function (string $input, ?float $expected) {
    expect(TelegramBuyFlow::parseAmount($input))->toBe($expected);
})->with([
    ['150', 150.0], ['$150', 150.0], ['150,5', 150.5], [' 99.99 ', 99.99], ['abc', null], ['0', null], ['1.234', null],
]);

/**
 * Replace the default fake with method-specific responses (earlier fakes would win otherwise).
 *
 * @param  array<string, mixed>  $stubs
 */
function fakeTelegramWith(array $stubs): void
{
    Http::swap(new Factory(app('events')));
    Http::fake([...$stubs, 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]])]);
}

test('a connection reset while talking to telegram is retried', function () {
    Sleep::fake();
    fakeTelegramWith(['api.telegram.org/*/editMessageText' => Http::sequence()->pushFailedConnection()->push(['ok' => true, 'result' => true])]);

    tapButton("ok:{$this->signal->id}:50", messageId: 99)->assertNoContent();

    expect(collect(telegramCalls('editMessageText'))->last()['text'])->toContain('Order #');
});

test('an order still counts when telegram cannot be reached afterwards', function () {
    Sleep::fake();
    Log::spy();
    fakeTelegramWith(['api.telegram.org/*/editMessageText' => Http::failedConnection()]);

    tapButton("ok:{$this->signal->id}:50", messageId: 99)->assertNoContent();

    $buy = Order::where('side', 'buy')->sole();
    expect(collect(telegramCalls('answerCallbackQuery'))->last()['text'])->toBe("Order #{$buy->id} dibuat");
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'alur beli'));
});
