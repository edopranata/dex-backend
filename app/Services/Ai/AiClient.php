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
 * Minimal JSON-mode client over a chain of LLM providers: the primary one first, then the fallback
 * (e.g. free Gemini, then paid xAI) whenever the primary is paused or out of budget.
 *
 * Every provider has its own per-minute and per-day budget, and its own pause after errors (quota,
 * overload, unknown model, rejected key), so a limit on one never stops the other. Callers fall back
 * to the lexicon/template whenever this returns null.
 */
class AiClient
{
    /** Providers this client can talk to; xAI and OpenAI share the OpenAI chat-completions format. */
    public const PROVIDERS = ['gemini' => 'Google Gemini', 'xai' => 'xAI Grok', 'openai' => 'OpenAI', 'ollama' => 'Ollama (lokal)'];

    private ?string $lastProvider = null;

    /**
     * Configured providers in the order they are tried (primary, then fallback); ones without a key are skipped.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        $chain = array_unique([config('market.ai.provider'), config('market.ai.fallback_provider')]);

        return array_values(array_filter(
            $chain,
            fn ($provider): bool => is_string($provider)
                && isset(self::PROVIDERS[$provider])
                && filled(config("market.ai.{$provider}.key"))
                && config("market.ai.{$provider}.enabled", true),
        ));
    }

    /**
     * The provider that would answer right now: the first one in the chain that is not paused.
     */
    public function provider(): ?string
    {
        foreach ($this->providers() as $provider) {
            if ($this->activePause($provider) === null) {
                return $provider;
            }
        }

        return $this->providers()[0] ?? null;
    }

    /**
     * The provider that answered the last successful json() call on this instance.
     */
    public function lastProvider(): ?string
    {
        return $this->lastProvider;
    }

    public function isEnabled(): bool
    {
        return $this->providers() !== [];
    }

    /**
     * Round-trip check of every configured provider for the admin panel. Bypasses budgets and pauses;
     * a success clears that provider's pause. Error messages never contain the key (sent in headers).
     *
     * @return list<array{ok: bool, provider: string|null, label: string|null, model: string|null, latency_ms: int|null, error: string|null}>
     */
    public function ping(): array
    {
        if (! $this->isEnabled()) {
            return [['ok' => false, 'provider' => null, 'label' => null, 'model' => null, 'latency_ms' => null, 'error' => 'Provider nonaktif atau API key kosong.']];
        }

        return array_map(function (string $provider): array {
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
                $this->resume($provider);
                Cache::put($this->key('last-success', $provider), now()->toIso8601String(), now()->addDays(30));
            }

            return [
                'ok' => $ok,
                'provider' => $provider,
                'label' => self::PROVIDERS[$provider],
                'model' => config("market.ai.{$provider}.model"),
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                'error' => $error,
            ];
        }, $this->providers());
    }

    /**
     * Send a prompt and decode the JSON object the model returns, trying the fallback provider when the
     * primary is paused, over budget or fails. Null when no provider could answer.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $system, string $prompt): ?array
    {
        $this->lastProvider = null;

        foreach ($this->providers() as $provider) {
            if (! $this->acquireBudget($provider)) {
                continue;
            }

            try {
                $text = $this->send($provider, $system, $prompt);
            } catch (RequestException $e) {
                $this->pauseAfter($provider, $e->response);

                continue;
            } catch (Throwable $e) {
                $this->pause($provider, now()->addMinutes(2), "Koneksi gagal: {$e->getMessage()}");

                continue;
            }

            Cache::put($this->key('last-success', $provider), now()->toIso8601String(), now()->addDays(30));

            $decoded = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $text)), true);

            if (is_array($decoded)) {
                $this->lastProvider = $provider;

                return $decoded;
            }
        }

        return null;
    }

    /**
     * Current state of every configured provider for the admin panel.
     *
     * @return array{enabled: bool, providers: list<array{provider: string, label: string, role: string, model: string|null, paused_until: string|null, reason: string|null, requests_today: int, max_per_day: int, max_per_minute: int, last_success_at: string|null}>}
     */
    public function status(): array
    {
        $providers = $this->providers();

        return [
            'enabled' => $providers !== [],
            'providers' => array_map(function (string $provider, int $index): array {
                $pause = $this->activePause($provider);

                return [
                    'provider' => $provider,
                    'label' => self::PROVIDERS[$provider],
                    'role' => $index === 0 ? 'primary' : 'fallback',
                    'model' => config("market.ai.{$provider}.model"),
                    'paused_until' => $pause['until'] ?? null,
                    'reason' => $pause['reason'] ?? null,
                    'requests_today' => (int) Cache::get($this->dayKey($provider), 0),
                    'max_per_day' => (int) config("market.ai.{$provider}.max_per_day"),
                    'max_per_minute' => (int) config("market.ai.{$provider}.max_per_minute"),
                    'last_success_at' => Cache::get($this->key('last-success', $provider)),
                ];
            }, $providers, array_keys($providers)),
        ];
    }

    /**
     * Clear an automatic pause (of one provider, or all), e.g. after a key or model was changed.
     */
    public function resume(?string $provider = null): void
    {
        foreach ($provider !== null ? [$provider] : array_keys(self::PROVIDERS) as $name) {
            Cache::forget($this->key('pause', $name));
        }
    }

    private function acquireBudget(string $provider): bool
    {
        if ($this->activePause($provider) !== null) {
            return false;
        }

        if (! RateLimiter::attempt("ai-requests:{$provider}", (int) config("market.ai.{$provider}.max_per_minute"), fn (): bool => true, 60)) {
            return false;
        }

        Cache::add($this->dayKey($provider), 0, now()->addDays(2));

        if (Cache::increment($this->dayKey($provider)) > (int) config("market.ai.{$provider}.max_per_day")) {
            $this->pause($provider, now('Asia/Jakarta')->addDay()->startOfDay(), 'Batas harian aplikasi tercapai (atur di Pengaturan → AI).');

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
        $message = (string) ($response->json('error.message') ?? $response->json('error') ?? $response->body());
        $model = config("market.ai.{$provider}.model");

        [$until, $reason] = match (true) {
            $status === 429 && $this->isDailyQuota($response) => [
                now('America/Los_Angeles')->addDay()->startOfDay(),
                'Kuota harian habis',
            ],
            $status === 429 => [now()->addSeconds(max(60, $this->retryDelaySeconds($response) + 5)), 'Batas request per menit tercapai'],
            $status >= 500 => [now()->addMinutes(2), 'Server AI sedang sibuk'],
            $status === 404 => [now()->addHours(6), "Model tidak tersedia: {$model}"],
            $this->isOutOfCredits($response) => [now()->addHours(6), 'Kredit atau batas belanja habis'],
            in_array($status, [401, 403], true) => [now()->addHours(6), 'API key ditolak'],
            default => [now()->addHour(), "Request ditolak (HTTP {$status})"],
        };

        $this->pause($provider, $until, "{$reason} — HTTP {$status}: ".mb_substr(is_string($message) ? $message : json_encode($message), 0, 200));
    }

    private function pause(string $provider, Carbon $until, string $reason): void
    {
        Cache::put($this->key('pause', $provider), ['until' => $until->toIso8601String(), 'reason' => $reason], $until);

        $label = self::PROVIDERS[$provider];
        $message = "AI {$label} dijeda sampai {$until->copy()->setTimezone('Asia/Jakarta')->format('d M H:i')} WIB: {$reason}";
        Log::warning($message);

        // Short pauses (per-minute limit, busy server) recover by themselves; only long ones need attention.
        if ($until->gt(now()->addMinutes(30))) {
            $standby = array_values(array_filter($this->providers(), fn (string $other): bool => $other !== $provider && $this->activePause($other) === null));
            $fallback = $standby !== []
                ? ' Sementara dialihkan ke '.self::PROVIDERS[$standby[0]].'.'
                : ' Analisis tetap jalan memakai lexicon/template.';

            app(TelegramNotifier::class)->system('AI dijeda', $message.$fallback, "ai-pause-{$provider}", 360);
        }
    }

    /**
     * @return array{until: string, reason: string}|null
     */
    private function activePause(string $provider): ?array
    {
        $pause = Cache::get($this->key('pause', $provider));

        return is_array($pause) && Carbon::parse($pause['until'])->isFuture() ? $pause : null;
    }

    private function isDailyQuota(Response $response): bool
    {
        $body = $response->body();

        return str_contains($body, 'PerDay') || str_contains($body, 'insufficient_quota');
    }

    private function isOutOfCredits(Response $response): bool
    {
        return $response->status() === 402 || preg_match('/credit|spending limit|billing/i', $response->body()) === 1;
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

    private function key(string $name, string $provider): string
    {
        return "ai:{$name}:{$provider}";
    }

    private function dayKey(string $provider): string
    {
        return "ai:requests:{$provider}:".now('Asia/Jakarta')->format('Y-m-d');
    }

    private function send(string $provider, string $system, string $prompt): ?string
    {
        return $provider === 'gemini' ? $this->gemini($system, $prompt) : $this->chatCompletions($provider, $system, $prompt);
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

    /**
     * OpenAI-style chat completions in JSON mode (OpenAI, xAI and local Ollama).
     */
    private function chatCompletions(string $provider, string $system, string $prompt): ?string
    {
        return Http::withToken(config("market.ai.{$provider}.key"))
            ->timeout((int) config("market.ai.{$provider}.timeout", 60))
            ->post(rtrim((string) config("market.ai.{$provider}.base_url"), '/').'/chat/completions', [
                ...config("market.ai.{$provider}.request", []),
                'model' => config("market.ai.{$provider}.model"),
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
