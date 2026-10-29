<?php

use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Http;

const GEMINI_URL = 'generativelanguage.googleapis.com/*';

beforeEach(function () {
    config([
        'market.ai.provider' => 'gemini',
        'market.ai.gemini.key' => 'test-key',
        'market.ai.gemini.model' => 'gemini-flash-lite-latest',
        'market.ai.max_requests_per_minute' => 5,
        'market.ai.max_requests_per_day' => 250,
    ]);
});

function geminiOk(): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => '{"ok": true}']]]]]];
}

function gemini429(string $quotaId, string $retryDelay = '41s'): array
{
    return ['error' => [
        'code' => 429,
        'message' => 'You exceeded your current quota, please check your plan and billing details.',
        'status' => 'RESOURCE_EXHAUSTED',
        'details' => [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => $quotaId]]],
            ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay],
        ],
    ]];
}

test('a successful call returns decoded json and records the success', function () {
    Http::fake([GEMINI_URL => Http::response(geminiOk())]);
    $ai = app(AiClient::class);

    expect($ai->json('system', 'prompt'))->toBe(['ok' => true])
        ->and($ai->status()['requests_today'])->toBe(1)
        ->and($ai->status()['last_success_at'])->not->toBeNull();
});

test('a daily quota error pauses ai until the quota resets and stops further calls', function () {
    Http::fake([GEMINI_URL => Http::response(gemini429('GenerateRequestsPerDayPerProjectPerModel-FreeTier'), 429)]);
    $ai = app(AiClient::class);

    expect($ai->json('s', 'p'))->toBeNull()
        ->and($ai->json('s', 'p'))->toBeNull()
        ->and($ai->json('s', 'p'))->toBeNull();

    Http::assertSentCount(1);
    expect($ai->status()['reason'])->toContain('Kuota harian habis')
        ->and(now()->diffInHours($ai->status()['paused_until']))->toBeGreaterThan(0);
});

test('a per-minute quota error pauses for the provider retry delay', function () {
    Http::fake([GEMINI_URL => Http::response(gemini429('GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '41s'), 429)]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');

    expect(now()->diffInSeconds($ai->status()['paused_until']))->toBeBetween(55, 61)
        ->and($ai->status()['reason'])->toContain('per menit');
});

test('an unknown model and an overloaded server pause for different lengths', function () {
    Http::fake([GEMINI_URL => Http::sequence()
        ->push(['error' => ['code' => 404, 'message' => 'model is no longer available to new users']], 404)
        ->push(['error' => ['code' => 503, 'message' => 'high demand']], 503)]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');
    expect($ai->status()['reason'])->toContain('Model tidak tersedia: gemini-flash-lite-latest')
        ->and(now()->diffInHours($ai->status()['paused_until']))->toBeGreaterThan(5);

    $ai->resume();
    $ai->json('s', 'p');
    expect(now()->diffInMinutes($ai->status()['paused_until']))->toBeLessThanOrEqual(2);
});

test('requests above the per-minute budget are skipped without calling the api', function () {
    config(['market.ai.max_requests_per_minute' => 2]);
    Http::fake([GEMINI_URL => Http::response(geminiOk())]);
    $ai = app(AiClient::class);

    $results = [$ai->json('s', 'p'), $ai->json('s', 'p'), $ai->json('s', 'p')];

    Http::assertSentCount(2);
    expect($results[2])->toBeNull()
        ->and($ai->status()['paused_until'])->toBeNull();
});

test('the daily budget caps requests and pauses until tomorrow', function () {
    config(['market.ai.max_requests_per_day' => 1]);
    Http::fake([GEMINI_URL => Http::response(geminiOk())]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');
    $ai->json('s', 'p');

    Http::assertSentCount(1);
    expect($ai->status()['reason'])->toContain('Batas harian aplikasi');
});

test('ping bypasses a pause and clears it on success', function () {
    Http::fake([GEMINI_URL => Http::sequence()
        ->push(gemini429('GenerateRequestsPerDayPerProjectPerModel-FreeTier'), 429)
        ->push(geminiOk())]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');
    expect($ai->status()['paused_until'])->not->toBeNull();

    expect($ai->ping()['ok'])->toBeTrue()
        ->and($ai->status()['paused_until'])->toBeNull();
});

test('changing an ai setting in the admin panel lifts the pause and reports status', function () {
    Http::fake([GEMINI_URL => Http::response(['error' => ['code' => 404, 'message' => 'gone']], 404)]);
    app(AiClient::class)->json('s', 'p');
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('ai_status.paused_until', fn ($v) => $v !== null);

    $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.ai.provider' => 'gemini', 'market.ai.gemini.key' => 'test-key', 'market.ai.gemini.model' => 'gemini-3.5-flash-lite']])
        ->assertOk()
        ->assertJsonPath('ai_status.paused_until', null)
        ->assertJsonPath('ai_status.model', 'gemini-3.5-flash-lite');
});
