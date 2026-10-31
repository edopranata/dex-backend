<?php

use App\Models\ContainerMetric;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function containerSample(array $overrides = []): array
{
    return [
        'inspect' => implode("\n", [
            '{"name":"/dex-app-1","project":"dex","service":"app","state":"running","health":"healthy","exit_code":0,"restarts":2,"started_at":"2026-10-31T08:00:00.123456789Z"}',
            '{"name":"/dex-web-1","project":"dex","service":"web","state":"exited","health":null,"exit_code":137,"restarts":0,"started_at":"2026-10-31T08:00:00Z"}',
            '{"name":"/dex-migrate-1","project":"dex","service":"migrate","state":"exited","health":null,"exit_code":0,"restarts":0,"started_at":"2026-10-31T08:00:00Z"}',
            '{"name":"/dex-mysql-1","project":"dex","service":"mysql","state":"running","health":"unhealthy","exit_code":0,"restarts":0,"started_at":"0001-01-01T00:00:00Z"}',
        ]),
        'stats' => '{"Name":"dex-app-1","CPUPerc":"12.50%","MemUsage":"1.5GiB / 5.787GiB"}'."\n".'{"Name":"dex-mysql-1","CPUPerc":"--","MemUsage":"512KiB / 5.787GiB"}',
        'host' => ['memory_total' => 6_000_000_000, 'memory_available' => 4_000_000_000, 'disk_total' => 80_000_000_000, 'disk_used' => 10_000_000_000, 'load' => 0.42, 'cores' => 3],
        ...$overrides,
    ];
}

beforeEach(function () {
    config(['market.monitoring.container_token' => 'secret-token']);
});

test('a sample needs the token, and the endpoint is closed without one configured', function () {
    $this->postJson('/api/internal/container-metrics', containerSample())->assertUnauthorized();
    $this->withToken('wrong')->postJson('/api/internal/container-metrics', containerSample())->assertUnauthorized();

    config(['market.monitoring.container_token' => '']);
    $this->withToken('')->postJson('/api/internal/container-metrics', containerSample())->assertNotFound();

    expect(ContainerMetric::count())->toBe(0);
});

test('a sample is stored and answered with the chosen interval', function () {
    config(['market.monitoring.container_interval_seconds' => '25']);

    $this->withToken('secret-token')->postJson('/api/internal/container-metrics', containerSample())
        ->assertOk()
        ->assertExactJson(['interval_seconds' => 25]);

    $app = ContainerMetric::firstWhere('name', 'dex-app-1');
    $mysql = ContainerMetric::firstWhere('name', 'dex-mysql-1');

    expect(ContainerMetric::count())->toBe(4)
        ->and($app->cpu_percent)->toBe(12.5)
        ->and($app->memory_mb)->toBe(1536.0)
        ->and($app->restarts)->toBe(2)
        ->and($app->started_at->utc()->toDateTimeString())->toBe('2026-10-31 08:00:00')
        ->and($mysql->cpu_percent)->toBeNull()
        ->and($mysql->memory_mb)->toBe(0.5)
        ->and($mysql->started_at)->toBeNull();
});

test('only admins see the containers, problems first, with a one-shot job not counted as a problem', function () {
    $this->withToken('secret-token')->postJson('/api/internal/container-metrics', containerSample())->assertOk();

    $this->getJson('/api/admin/system/containers')->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson('/api/admin/system/containers')->assertForbidden();

    $response = $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/api/admin/system/containers')
        ->assertOk()
        ->assertJsonPath('stale', false)
        ->assertJsonPath('host.cores', 3);

    expect($response->json('containers.*.label'))->toBe(['Tidak sehat', 'Mati (kode 137)', 'Sehat', 'Selesai'])
        ->and($response->json('containers.2.history.0.cpu'))->toEqual(12.5);
});

test('old data is marked as stale', function () {
    $this->withToken('secret-token')->postJson('/api/internal/container-metrics', containerSample())->assertOk();

    $this->travel(46)->seconds();

    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/api/admin/system/containers')
        ->assertJsonPath('stale', true);
});

test('the interval is a choice of 10 to 60 seconds in steps of 5', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.monitoring.container_interval_seconds' => '10']])->assertOk();
    expect(config('market.monitoring.container_interval_seconds'))->toBe('10');

    foreach (['5', '12', '65'] as $invalid) {
        $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.monitoring.container_interval_seconds' => $invalid]])->assertUnprocessable();
    }
});

test('samples older than the retention period are pruned', function () {
    $this->withToken('secret-token')->postJson('/api/internal/container-metrics', containerSample())->assertOk();
    $this->travel(8)->days();
    $this->withToken('secret-token')->postJson('/api/internal/container-metrics', containerSample())->assertOk();

    Artisan::call('model:prune', ['--model' => [ContainerMetric::class]]);

    expect(ContainerMetric::count())->toBe(4);
});
