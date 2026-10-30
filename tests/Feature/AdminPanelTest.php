<?php

use App\Jobs\RunAdminCommand;
use App\Models\Setting;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\Admin\SettingsStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
});

test('only admins can open the admin api', function () {
    $this->getJson('/api/admin/settings')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/admin/settings')->assertForbidden();
    $this->actingAs($this->admin)->getJson('/api/admin/settings')->assertOk()->assertJsonPath('groups.0.key', 'ai');
});

test('api keys are stored encrypted, applied to config and never returned', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/admin/settings', ['values' => ['market.ai.provider' => 'gemini', 'market.ai.gemini.key' => 'AIzaSecretKey1234']])
        ->assertOk()
        ->assertDontSee('AIzaSecretKey1234')
        ->assertJsonPath('groups.0.fields.2.masked', '••••1234')
        ->assertJsonPath('groups.0.fields.2.source', 'panel');

    expect(Setting::firstWhere('key', 'market.ai.gemini.key')->value)->not->toContain('AIzaSecretKey1234')
        ->and(config('market.ai.gemini.key'))->toBe('AIzaSecretKey1234')
        ->and(config('market.ai.provider'))->toBe('gemini');
});

test('an empty secret keeps the current key and reset restores the env value', function () {
    $default = app(SettingsStore::class)->default('market.ai.gemini.model');
    $this->actingAs($this->admin)->putJson('/api/admin/settings', ['values' => ['market.ai.gemini.key' => 'first-key-9999', 'market.ai.gemini.model' => 'gemini-test']]);
    $this->actingAs($this->admin)->putJson('/api/admin/settings', ['values' => ['market.ai.gemini.key' => '']]);

    expect(config('market.ai.gemini.key'))->toBe('first-key-9999');

    $this->actingAs($this->admin)->postJson('/api/admin/settings/reset', ['keys' => ['market.ai.gemini.model']])->assertOk();

    expect(config('market.ai.gemini.model'))->toBe($default);
});

test('settings are validated by type, range, known keys and weight sum', function () {
    $this->actingAs($this->admin)->putJson('/api/admin/settings', ['values' => ['market.simulator.max_tokens' => 0]])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/admin/settings', ['values' => ['app.key' => 'x']])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/admin/settings', ['values' => ['market.analysis.weights.technical' => 0.9]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('values');
    $this->actingAs($this->admin)->putJson('/api/admin/settings', ['values' => [
        'market.analysis.weights.technical' => 0.4,
        'market.analysis.weights.fundamental' => 0.1,
    ]])->assertOk();

    expect(config('market.analysis.weights.technical'))->toBe(0.4);
});

test('backups can be listed and downloaded and manual backups respect the cooldown', function () {
    $dir = sys_get_temp_dir().'/dex-backups-'.uniqid();
    File::ensureDirectoryExists($dir);
    config(['market.backup.path' => $dir, 'market.backup.manual_cooldown_minutes' => 60]);
    file_put_contents("{$dir}/dex-2026-10-29-0230.sql.gz", gzencode('-- dump'));

    $this->actingAs($this->admin)->getJson('/api/admin/backups')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'dex-2026-10-29-0230.sql.gz')
        ->assertJsonPath('manual_available_at', fn ($value) => $value !== null);

    $this->actingAs($this->admin)->get('/api/admin/backups/dex-2026-10-29-0230.sql.gz/download')->assertOk()->assertDownload('dex-2026-10-29-0230.sql.gz');
    $this->actingAs($this->admin)->get('/api/admin/backups/..%2F.env/download')->assertNotFound();
    $this->actingAs($this->admin)->postJson('/api/admin/backups')->assertStatus(429)->assertJsonPath('manual_available_at', fn ($v) => $v !== null);

    File::deleteDirectory($dir);
});

test('backup status includes the last R2 copy and restore check written by the server scripts', function () {
    $dir = sys_get_temp_dir().'/dex-backups-'.uniqid();
    File::ensureDirectoryExists($dir);
    config(['market.backup.path' => $dir]);

    $this->actingAs($this->admin)->getJson('/api/admin/backups')
        ->assertOk()
        ->assertJsonPath('offsite', null)
        ->assertJsonPath('restore_check', null);

    file_put_contents("{$dir}/offsite-status.json", '{"ok":false,"file":"dex-2026-10-30-023000.sql.gz","size":880,"at":"2026-10-30T02:30:05+07:00","error":"403 Forbidden"}');
    file_put_contents("{$dir}/restore-check.json", '{"ok":true,"file":"dex-2026-10-30-023000.sql.gz","source":"R2","at":"2026-10-30T17:00:00+07:00","seconds":4,"tables":30,"live_tables":30,"migrations":29,"live_migrations":29,"users":3,"tokens":120,"orders":40}');

    $this->actingAs($this->admin)->getJson('/api/admin/backups')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('offsite.ok', false)
        ->assertJsonPath('offsite.error', '403 Forbidden')
        ->assertJsonPath('restore_check.ok', true)
        ->assertJsonPath('restore_check.source', 'R2');

    File::deleteDirectory($dir);
});

test('log entries are parsed newest first with level filter and stack traces', function () {
    $file = 'laravel-2099-01-01.log';
    File::ensureDirectoryExists(storage_path('logs'));
    file_put_contents(storage_path("logs/{$file}"), implode("\n", [
        '[2099-01-01 10:00:00] production.INFO: Harga diperbarui',
        '[2099-01-01 10:01:00] production.ERROR: GeckoTerminal 429 {"exception":"x"}',
        '#0 /var/www/html/app/Services/MarketData/GeckoTerminalClient.php(90)',
        '#1 {main}',
        '[2099-01-01 10:02:00] production.WARNING: News feed failed',
    ])."\n");

    $this->actingAs($this->admin)->getJson("/api/admin/logs?file={$file}")
        ->assertOk()
        ->assertJsonCount(3, 'entries')
        ->assertJsonPath('entries.0.level', 'warning');

    $this->actingAs($this->admin)->getJson("/api/admin/logs?file={$file}&level=error")
        ->assertJsonCount(1, 'entries')
        ->assertJsonPath('entries.0.message', 'GeckoTerminal 429 {"exception":"x"}')
        ->assertJsonPath('entries.0.context', fn (string $context) => str_contains($context, 'GeckoTerminalClient.php'));

    $this->actingAs($this->admin)->getJson('/api/admin/logs?file=../../.env')->assertNotFound();

    File::delete(storage_path("logs/{$file}"));
});

test('scheduled task history is summarized and allowlisted commands can be queued', function () {
    Queue::fake();
    TaskRun::factory()->count(3)->create();
    TaskRun::factory()->create(['status' => 'failed', 'exit_code' => 1]);

    TaskRun::factory()->create(['command' => 'market:candles --timeframe=4h --timeframe=1d']);

    $tasks = collect($this->actingAs($this->admin)->getJson('/api/admin/tasks')->assertOk()->json('tasks'))->keyBy('command');

    expect($tasks['market:prices'])->toMatchArray(['last_status' => 'failed', 'failures_24h' => 1, 'runs_24h' => 4])
        ->and($tasks['model:prune'])->toMatchArray(['last_status' => null, 'last_run_at' => null, 'runs_24h' => 0])
        ->and($tasks['model:prune']['next_run_at'])->toEndWith('T03:00:00+00:00')
        ->and($tasks->has('market:candles --timeframe=4h --timeframe=1d'))->toBeFalse();

    $this->actingAs($this->admin)->postJson('/api/admin/tasks/run', ['command' => 'market:discover'])->assertAccepted();
    $this->actingAs($this->admin)->postJson('/api/admin/tasks/run', ['command' => 'migrate:fresh'])->assertUnprocessable();

    Queue::assertPushed(RunAdminCommand::class, 1);
});

test('admins manage users but cannot delete or demote themselves', function () {
    $this->actingAs($this->admin)->postJson('/api/admin/users', [
        'name' => 'Teman', 'email' => 'teman@example.com', 'password' => 'password-kuat-123',
    ])->assertCreated()->assertJsonPath('data.is_admin', false);

    $this->actingAs($this->admin)->deleteJson("/api/admin/users/{$this->admin->id}")->assertUnprocessable();
    $this->actingAs($this->admin)->patchJson("/api/admin/users/{$this->admin->id}", ['is_admin' => false])->assertUnprocessable();
    $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()->assertJsonCount(2, 'data');
});

test('the first user created from the command line becomes admin', function () {
    User::query()->delete();

    $this->artisan('app:create-user', ['--name' => 'Owner', '--email' => 'owner@example.com', '--password' => 'rahasia-kuat-123'])->assertSuccessful();
    $this->artisan('app:create-user', ['--name' => 'Guest', '--email' => 'guest@example.com', '--password' => 'rahasia-kuat-123'])->assertSuccessful();

    expect(User::firstWhere('email', 'owner@example.com')->is_admin)->toBeTrue()
        ->and(User::firstWhere('email', 'guest@example.com')->is_admin)->toBeFalse();
});
