<?php

use App\Models\User;
use App\Services\Admin\HorizonStatus;
use App\Services\Admin\SettingsStore;
use App\Services\Notifications\TelegramClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['app.url' => 'https://dexsim.test']);
    app(SettingsStore::class)->set(['market.telegram.enabled' => true, 'market.telegram.bot_token' => '123:secret-token']);

    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'DexSimulatebot', 'first_name' => 'Dex Simulator']]),
        'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://dexsim.test/api/telegram/webhook', 'pending_update_count' => 0]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);
});

/**
 * @return array<string, mixed>
 */
function telegramUpdate(string $text, int $chatId = 777): array
{
    return ['update_id' => 1, 'message' => ['chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['username' => 'edo'], 'text' => $text]];
}

function webhook(array $update, ?string $secret = null)
{
    return test()->postJson('/api/telegram/webhook', $update, [
        'X-Telegram-Bot-Api-Secret-Token' => $secret ?? app(TelegramClient::class)->webhookSecret(),
    ]);
}

test('a user links telegram by sending the one-time code from the app to the bot', function () {
    $user = User::factory()->create(['name' => 'Edo']);

    $link = $this->actingAs($user)->postJson('/api/profile/telegram')->assertOk();
    $code = $link->json('code');

    expect($code)->toMatch('/^[A-Z2-9]{8}$/')
        ->and($link->json('deep_link'))->toBe("https://t.me/DexSimulatebot?start={$code}");

    webhook(telegramUpdate("/start {$code}"))
        ->assertOk()
        ->assertJsonPath('method', 'sendMessage')
        ->assertJsonPath('chat_id', '777')
        ->assertJsonPath('text', fn (string $text) => str_contains($text, 'Terhubung dengan akun Edo'));

    expect($user->refresh()->telegram_chat_id)->toBe('777')
        ->and($user->telegram_username)->toBe('edo');
});

test('a code works once, can be typed without /start, and a new code replaces the old one', function () {
    $user = User::factory()->create();
    $first = $this->actingAs($user)->postJson('/api/profile/telegram')->json('code');
    $second = $this->actingAs($user)->postJson('/api/profile/telegram')->json('code');

    webhook(telegramUpdate("/start {$first}"))->assertJsonPath('text', fn (string $t) => str_contains($t, 'tidak valid'));
    webhook(telegramUpdate(strtolower($second)))->assertJsonPath('text', fn (string $t) => str_contains($t, 'Terhubung'));
    webhook(telegramUpdate("/start {$second}", 888))->assertJsonPath('text', fn (string $t) => str_contains($t, 'tidak valid'));

    expect($user->refresh()->telegram_chat_id)->toBe('777');
});

test('the webhook rejects requests without the secret', function () {
    webhook(telegramUpdate('/status'), 'wrong')->assertForbidden();
});

test('status shows the linked portfolio and stop unlinks the chat', function () {
    $user = User::factory()->create(['telegram_chat_id' => '777', 'balance_usd' => 10_000]);

    webhook(telegramUpdate('/status@DexSimulatebot'))->assertJsonPath('text', fn (string $t) => str_contains($t, 'Ekuitas: <b>$10,000.00</b>'));
    webhook(telegramUpdate('/stop'))->assertJsonPath('text', fn (string $t) => str_contains($t, 'diputus'));

    expect($user->refresh()->telegram_chat_id)->toBeNull();
    webhook(telegramUpdate('/start'))->assertJsonPath('text', fn (string $t) => str_contains($t, 'belum terhubung'));
});

test('users can send themselves a test message and unlink from the app', function () {
    $user = User::factory()->create(['telegram_chat_id' => '777']);

    $this->actingAs($user)->postJson('/api/profile/telegram/test')->assertOk()->assertJsonPath('ok', true);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '777');

    $this->actingAs($user)->deleteJson('/api/profile/telegram')->assertOk();
    expect($user->refresh()->telegram_chat_id)->toBeNull();
});

test('linking is unavailable until the admin enables telegram', function () {
    app(SettingsStore::class)->set(['market.telegram.enabled' => false]);

    $this->actingAs(User::factory()->create())->postJson('/api/profile/telegram')->assertStatus(422);
});

test('saving the bot token connects the webhook with its secret', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.telegram.bot_token' => '123:secret-token']])->assertOk();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook')
        && $request['url'] === 'https://dexsim.test/api/telegram/webhook'
        && $request['secret_token'] === app(TelegramClient::class)->webhookSecret());

    $this->actingAs($admin)->getJson('/api/admin/telegram')
        ->assertOk()
        ->assertJsonPath('bot.username', 'DexSimulatebot')
        ->assertJsonPath('webhook.connected', true);
});

test('help lists the commands, with /horizon only for admins', function () {
    User::factory()->create(['telegram_chat_id' => '777']);
    User::factory()->create(['telegram_chat_id' => '888', 'is_admin' => true]);

    webhook(telegramUpdate('/help'))->assertJsonPath('text', fn (string $t) => str_contains($t, '/status') && ! str_contains($t, '/horizon'));
    webhook(telegramUpdate('/help', 888))->assertJsonPath('text', fn (string $t) => str_contains($t, '/horizon'));
});

test('/horizon shows queue health to admins only', function () {
    User::factory()->create(['telegram_chat_id' => '777']);
    User::factory()->create(['telegram_chat_id' => '888', 'is_admin' => true]);
    $this->mock(HorizonStatus::class)->shouldReceive('summary')->andReturn([
        'status' => 'running',
        'queues' => [['name' => 'default', 'length' => 0, 'wait' => 0, 'processes' => 1], ['name' => 'market-data', 'length' => 12, 'wait' => 140, 'processes' => 1]],
        'jobs_per_minute' => 6.5,
        'failed_24h' => 0,
    ]);

    webhook(telegramUpdate('/horizon'))->assertJsonPath('text', 'Perintah /horizon hanya untuk admin.');
    webhook(telegramUpdate('/horizon', 888))->assertJsonPath('text', fn (string $t) => str_contains($t, 'berjalan')
        && str_contains($t, 'market-data: 12 menunggu · tunggu ±140 dtk')
        && str_contains($t, 'https://dexsim.test/horizon'));
});

test('the command menu is synced for everyone and for admin chats', function () {
    User::factory()->create(['telegram_chat_id' => '777']);
    User::factory()->create(['telegram_chat_id' => '888', 'is_admin' => true]);

    $this->artisan('app:telegram-sync')->assertSuccessful();

    $calls = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $r): bool => str_ends_with($r->url(), '/setMyCommands'))->values();

    expect($calls)->toHaveCount(2)
        ->and(collect($calls[0]['commands'])->pluck('command')->all())->toBe(['status', 'help', 'stop'])
        ->and($calls[0]->data())->not->toHaveKey('scope')
        ->and($calls[1]['scope'])->toBe(['type' => 'chat', 'chat_id' => '888'])
        ->and(collect($calls[1]['commands'])->pluck('command')->all())->toContain('horizon');
});
