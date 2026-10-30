<?php

use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Http;

const GEMINI_URL = 'generativelanguage.googleapis.com/*';
const XAI_URL = 'api.x.ai/*';

beforeEach(function () {
    config([
        'market.ai.provider' => 'gemini',
        'market.ai.gemini.key' => 'test-key',
        'market.ai.gemini.model' => 'gemini-flash-lite-latest',
        'market.ai.gemini.max_per_minute' => 5,
        'market.ai.gemini.max_per_day' => 250,
        'market.ai.fallback_provider' => 'none',
        'market.ai.xai.key' => 'xai-test-key',
        'market.ai.xai.model' => 'grok-4.20-0309-non-reasoning',
        'market.ai.xai.max_per_minute' => 30,
        'market.ai.xai.max_per_day' => 100,
    ]);
});

function geminiOk(): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => '{"ok": true}']]]]]];
}

function xaiOk(string $json = '{"ok": true}'): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $json]]]];
}

/** Status of one provider from AiClient::status(). */
function aiStatusOf(string $provider): array
{
    return collect(app(AiClient::class)->status()['providers'])->firstWhere('provider', $provider);
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
        ->and(aiStatusOf('gemini')['requests_today'])->toBe(1)
        ->and(aiStatusOf('gemini')['last_success_at'])->not->toBeNull();
});

test('a daily quota error pauses ai until the quota resets and stops further calls', function () {
    Http::fake([GEMINI_URL => Http::response(gemini429('GenerateRequestsPerDayPerProjectPerModel-FreeTier'), 429)]);
    $ai = app(AiClient::class);

    expect($ai->json('s', 'p'))->toBeNull()
        ->and($ai->json('s', 'p'))->toBeNull()
        ->and($ai->json('s', 'p'))->toBeNull();

    Http::assertSentCount(1);
    expect(aiStatusOf('gemini')['reason'])->toContain('Kuota harian habis')
        ->and(now()->diffInHours(aiStatusOf('gemini')['paused_until']))->toBeGreaterThan(0);
});

test('a per-minute quota error pauses for the provider retry delay', function () {
    Http::fake([GEMINI_URL => Http::response(gemini429('GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '41s'), 429)]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');

    expect(now()->diffInSeconds(aiStatusOf('gemini')['paused_until']))->toBeBetween(55, 61)
        ->and(aiStatusOf('gemini')['reason'])->toContain('per menit');
});

test('an unknown model and an overloaded server pause for different lengths', function () {
    Http::fake([GEMINI_URL => Http::sequence()
        ->push(['error' => ['code' => 404, 'message' => 'model is no longer available to new users']], 404)
        ->push(['error' => ['code' => 503, 'message' => 'high demand']], 503)]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');
    expect(aiStatusOf('gemini')['reason'])->toContain('Model tidak tersedia: gemini-flash-lite-latest')
        ->and(now()->diffInHours(aiStatusOf('gemini')['paused_until']))->toBeGreaterThan(5);

    $ai->resume();
    $ai->json('s', 'p');
    expect(now()->diffInMinutes(aiStatusOf('gemini')['paused_until']))->toBeLessThanOrEqual(2);
});

test('requests above the per-minute budget are skipped without calling the api', function () {
    config(['market.ai.gemini.max_per_minute' => 2]);
    Http::fake([GEMINI_URL => Http::response(geminiOk())]);
    $ai = app(AiClient::class);

    $results = [$ai->json('s', 'p'), $ai->json('s', 'p'), $ai->json('s', 'p')];

    Http::assertSentCount(2);
    expect($results[2])->toBeNull()
        ->and(aiStatusOf('gemini')['paused_until'])->toBeNull();
});

test('the daily budget caps requests and pauses until tomorrow', function () {
    config(['market.ai.gemini.max_per_day' => 1]);
    Http::fake([GEMINI_URL => Http::response(geminiOk())]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');
    $ai->json('s', 'p');

    Http::assertSentCount(1);
    expect(aiStatusOf('gemini')['reason'])->toContain('Batas harian aplikasi');
});

test('ping bypasses a pause and clears it on success', function () {
    Http::fake([GEMINI_URL => Http::sequence()
        ->push(gemini429('GenerateRequestsPerDayPerProjectPerModel-FreeTier'), 429)
        ->push(geminiOk())]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');
    expect(aiStatusOf('gemini')['paused_until'])->not->toBeNull();

    expect($ai->ping()[0]['ok'])->toBeTrue()
        ->and(aiStatusOf('gemini')['paused_until'])->toBeNull();
});

test('changing an ai setting in the admin panel lifts the pause and reports status', function () {
    Http::fake([GEMINI_URL => Http::response(['error' => ['code' => 404, 'message' => 'gone']], 404)]);
    app(AiClient::class)->json('s', 'p');
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->getJson('/api/admin/settings')
        ->assertOk()
        ->assertJsonPath('ai_status.providers.0.paused_until', fn ($v) => $v !== null);

    $this->actingAs($admin)->putJson('/api/admin/settings', ['values' => ['market.ai.provider' => 'gemini', 'market.ai.gemini.key' => 'test-key', 'market.ai.gemini.model' => 'gemini-3.5-flash-lite']])
        ->assertOk()
        ->assertJsonPath('ai_status.providers.0.paused_until', null)
        ->assertJsonPath('ai_status.providers.0.model', 'gemini-3.5-flash-lite');
});

test('xai is called through its openai-compatible endpoint in json mode', function () {
    config(['market.ai.provider' => 'xai']);
    Http::fake([XAI_URL => Http::response(xaiOk('{"summary": "naik"}'))]);
    $ai = app(AiClient::class);

    expect($ai->json('system', 'prompt'))->toBe(['summary' => 'naik'])
        ->and($ai->lastProvider())->toBe('xai');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.x.ai/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer xai-test-key')
        && $request['model'] === 'grok-4.20-0309-non-reasoning'
        && $request['response_format'] === ['type' => 'json_object']);
});

test('the fallback provider answers while the primary is paused, each with its own pause', function () {
    config(['market.ai.fallback_provider' => 'xai']);
    Http::fake([
        GEMINI_URL => Http::response(gemini429('GenerateRequestsPerDayPerProjectPerModel-FreeTier'), 429),
        XAI_URL => Http::response(xaiOk()),
    ]);
    $ai = app(AiClient::class);

    expect($ai->json('s', 'p'))->toBe(['ok' => true])
        ->and($ai->lastProvider())->toBe('xai')
        ->and($ai->json('s', 'p'))->toBe(['ok' => true])
        ->and($ai->provider())->toBe('xai');

    // Gemini is asked once, then skipped while paused; xAI is not paused by Gemini's quota.
    Http::assertSentCount(3);
    expect(aiStatusOf('gemini')['reason'])->toContain('Kuota harian habis')
        ->and(aiStatusOf('xai')['paused_until'])->toBeNull()
        ->and(aiStatusOf('xai')['role'])->toBe('fallback')
        ->and(aiStatusOf('xai')['requests_today'])->toBe(2);
});

test('the fallback also takes over when the primary is over its own budget', function () {
    config(['market.ai.fallback_provider' => 'xai', 'market.ai.gemini.max_per_minute' => 1]);
    Http::fake([GEMINI_URL => Http::response(geminiOk()), XAI_URL => Http::response(xaiOk())]);
    $ai = app(AiClient::class);

    $ai->json('s', 'p');
    expect($ai->lastProvider())->toBe('gemini');

    $ai->json('s', 'p');
    expect($ai->lastProvider())->toBe('xai');
});

test('ai returns null when every provider is paused', function () {
    config(['market.ai.fallback_provider' => 'xai']);
    Http::fake([
        GEMINI_URL => Http::response(['error' => ['code' => 503, 'message' => 'busy']], 503),
        XAI_URL => Http::response(['code' => 'Some requested entity was not found', 'error' => 'Your team has run out of credits.'], 403),
    ]);
    $ai = app(AiClient::class);

    expect($ai->json('s', 'p'))->toBeNull()
        ->and($ai->lastProvider())->toBeNull()
        ->and(aiStatusOf('xai')['reason'])->toContain('Kredit');
});

test('a provider without a key is left out of the chain', function () {
    config(['market.ai.fallback_provider' => 'openai', 'market.ai.openai.key' => null]);

    expect(app(AiClient::class)->providers())->toBe(['gemini']);
});

test('ping checks every provider in the chain', function () {
    config(['market.ai.fallback_provider' => 'xai']);
    Http::fake([GEMINI_URL => Http::response(geminiOk()), XAI_URL => Http::response(['error' => 'Incorrect API key provided'], 400)]);

    $results = app(AiClient::class)->ping();

    expect(array_column($results, 'provider'))->toBe(['gemini', 'xai'])
        ->and($results[0]['ok'])->toBeTrue()
        ->and($results[1]['ok'])->toBeFalse();
});
