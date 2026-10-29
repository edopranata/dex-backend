<?php

namespace App\Services\Ai;

use App\Services\Notifications\TelegramNotifier;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Minimal JSON-mode client for the configured LLM provider (Gemini or OpenAI).
 *
 * Free tiers allow only a handful of requests per minute, so every call goes through a per-minute
 * and per-day budget, and provider errors (quota, overload, unknown model, rejected key) pause AI
 * for a while instead of retrying on every job. Callers fall back to the lexicon/template whenever
 * this returns null.
 */
class AiClient
{
    private const PAUSE_KEY = 'ai:pause';

    private const LAST_SUCCESS_KEY = 'ai:last-success';

    public function provider(): ?string
    {
        $provider = config('market.ai.provider');

        if (! in_array($provider, ['gemini', 'openai'], true) || blank(config("market.ai.{$provider}.key"))) {
            return null;
        }

        return $provider;
    }

    public function isEnabled(): bool
    {
        return $this->provider() !== null;
    }

    /**
     * Round-trip check for the admin panel. Bypasses the budget and pause; a success clears the pause.
     * Error messages never contain the key (sent in headers).
     *
     * @return array{ok: bool, provider: string|null, model: string|null, latency_ms: int|null, error: string|null}
     */
    public function ping(): array
    {
        $provider = $this->provider();

        if ($provider === null) {
            return ['ok' => false, 'provider' => config('market.ai.provider'), 'model' => null, 'latency_ms' => null, 'error' => 'Provider nonaktif atau API key kosong.'];
        }

        $model = config("market.ai.{$provider}.model");
        $start = microtime(true);

        try {
            $text = $this->send($provider, 'Reply with JSON only.', '{"ping": true} -> reply {"ok": true}');
            $ok = is_string($text) && str_contains($text, 'ok');
            $error = $ok ? null : 'Respons tidak dikenali: '.mb_substr((string) $text, 0, 200);
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        if ($ok) {
            $this->resume();
            Cache::put(self::LAST_SUCCESS_KEY, now()->toIso8601String(), now()->addDays(30));
        }

        return ['ok' => $ok, 'provider' => $provider, 'model' => $model, 'latency_ms' => (int) round((microtime(true) - $start) * 1000), 'error' => $error];
    }

    /**
     * Send a prompt and decode the JSON object the model returns, or null when AI is unavailable,
     * paused or over budget.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $system, string $prompt): ?array
    {
        $provider = $this->provider();

        if ($provider === null || ! $this->acquireBudget()) {
            return null;
        }

        try {
            $text = $this->send($provider, $system, $prompt);
        } catch (RequestException $e) {
            $this->pauseAfter($provider, $e->response);

            return null;
        } catch (Throwable $e) {
            $this->pause(now()->addMinutes(2), "Koneksi ke AI gagal: {$e->getMessage()}");

            return null;
        }

        Cache::put(self::LAST_SUCCESS_KEY, now()->toIso8601String(), now()->addDays(30));

        $decoded = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $text)), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Current state for the admin panel.
     *
     * @return array{enabled: bool, provider: string|null, model: string|null, paused_until: string|null, reason: string|null, requests_today: int, max_per_day: int, max_per_minute: int, last_success_at: string|null}
     */
    public function status(): array
    {
        $provider = $this->provider();
        $pause = $this->activePause();

        return [
            'enabled' => $provider !== null,
            'provider' => $provider,
            'model' => $provider ? config("market.ai.{$provider}.model") : null,
            'paused_until' => $pause['until'] ?? null,
            'reason' => $pause['reason'] ?? null,
            'requests_today' => (int) Cache::get($this->dayKey(), 0),
            'max_per_day' => (int) config('market.ai.max_requests_per_day'),
            'max_per_minute' => (int) config('market.ai.max_requests_per_minute'),
            'last_success_at' => Cache::get(self::LAST_SUCCESS_KEY),
        ];
    }

    /**
     * Clear an automatic pause, e.g. after the key or model was changed.
     */
    public function resume(): void
    {
        Cache::forget(self::PAUSE_KEY);
    }

    private function acquireBudget(): bool
    {
        if ($this->activePause() !== null) {
            return false;
        }

        if (! RateLimiter::attempt('ai-requests', (int) config('market.ai.max_requests_per_minute'), fn (): bool => true, 60)) {
            return false;
        }

        Cache::add($this->dayKey(), 0, now()->addDays(2));

        if (Cache::increment($this->dayKey()) > (int) config('market.ai.max_requests_per_day')) {
            $this->pause(now('Asia/Jakarta')->addDay()->startOfDay(), 'Batas harian aplikasi tercapai (atur di Pengaturan → AI).');

            return false;
        }

        return true;
    }

    /**
     * Choose how long to pause from the provider's error response.
     */
    private function pauseAfter(string $provider, Response $response): void
    {
        $status = $response->status();
        $message = (string) ($response->json('error.message') ?? $response->body());

        [$until, $reason] = match (true) {
            $status === 429 && $this->isDailyQuota($response) => [
                now('America/Los_Angeles')->addDay()->startOfDay(),
                'Kuota harian habis',
            ],
            $status === 429 => [now()->addSeconds(max(60, $this->retryDelaySeconds($response) + 5)), 'Batas request per menit tercapai'],
            $status >= 500 => [now()->addMinutes(2), 'Server AI sedang sibuk'],
            $status === 404 => [now()->addHours(6), 'Model tidak tersedia: '.config("market.ai.{$provider}.model")],
            in_array($status, [401, 403], true) => [now()->addHours(6), 'API key ditolak'],
            default => [now()->addHour(), "Request ditolak (HTTP {$status})"],
        };

        $this->pause($until, "{$reason} — HTTP {$status}: ".mb_substr($message, 0, 200));
    }

    private function pause(Carbon $until, string $reason): void
    {
        Cache::put(self::PAUSE_KEY, ['until' => $until->toIso8601String(), 'reason' => $reason], $until);

        $message = "AI dijeda sampai {$until->copy()->setTimezone('Asia/Jakarta')->format('d M H:i')} WIB: {$reason}";
        Log::warning($message);

        // Short pauses (per-minute limit, busy server) recover by themselves; only long ones need attention.
        if ($until->gt(now()->addMinutes(30))) {
            app(TelegramNotifier::class)->system('AI dijeda', $message.' Analisis tetap jalan memakai lexicon/template.', 'ai-pause', 360);
        }
    }

    /**
     * @return array{until: string, reason: string}|null
     */
    private function activePause(): ?array
    {
        $pause = Cache::get(self::PAUSE_KEY);

        return is_array($pause) && Carbon::parse($pause['until'])->isFuture() ? $pause : null;
    }

    private function isDailyQuota(Response $response): bool
    {
        $body = $response->body();

        return str_contains($body, 'PerDay') || str_contains($body, 'insufficient_quota');
    }

    private function retryDelaySeconds(Response $response): int
    {
        foreach ($response->json('error.details') ?? [] as $detail) {
            if (isset($detail['retryDelay']) && preg_match('/^(\d+)/', (string) $detail['retryDelay'], $match)) {
                return (int) $match[1];
            }
        }

        return (int) ($response->header('Retry-After') ?: 0);
    }

    private function dayKey(): string
    {
        return 'ai:requests:'.now('Asia/Jakarta')->format('Y-m-d');
    }

    private function send(string $provider, string $system, string $prompt): ?string
    {
        return $provider === 'gemini' ? $this->gemini($system, $prompt) : $this->openAi($system, $prompt);
    }

    private function gemini(string $system, string $prompt): ?string
    {
        $model = config('market.ai.gemini.model');

        return Http::withHeaders(['x-goog-api-key' => config('market.ai.gemini.key')])
            ->timeout(60)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2],
            ])
            ->throw()
            ->json('candidates.0.content.parts.0.text');
    }

    private function openAi(string $system, string $prompt): ?string
    {
        return Http::withToken(config('market.ai.openai.key'))
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('market.ai.openai.model'),
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.2,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])
            ->throw()
            ->json('choices.0.message.content');
    }
}
