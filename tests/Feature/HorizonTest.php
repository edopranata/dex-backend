<?php

use App\Models\User;
use App\Services\Admin\SettingsStore;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Events\LongWaitDetected;

test('only admins can open the horizon dashboard', function () {
    $this->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/horizon')->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/horizon')
        ->assertOk()
        ->assertSee('Horizon');
});

test('a long queue wait alerts admins on telegram', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    app(SettingsStore::class)->set(['market.telegram.enabled' => true, 'market.telegram.bot_token' => '123:secret-token']);
    User::factory()->create(['is_admin' => true, 'telegram_chat_id' => '1', 'notification_preferences' => ['quiet_start_hour' => 0, 'quiet_end_hour' => 0]]);

    event(new LongWaitDetected('redis', 'default', 240));

    Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Antrean default menunggu terlalu lama')
        && str_contains($request['text'], '240 detik'));
});
