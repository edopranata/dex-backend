<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Minimal JSON-mode client for the configured LLM provider (Gemini or OpenAI).
 */
class AiClient
{
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
     * Round-trip check for the admin panel. Error messages never contain the key (sent in headers).
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
            $text = $provider === 'gemini'
                ? $this->gemini('Reply with JSON only.', '{"ping": true} -> reply {"ok": true}')
                : $this->openAi('Reply with JSON only.', '{"ping": true} -> reply {"ok": true}');
            $ok = is_string($text) && str_contains($text, 'ok');
            $error = $ok ? null : 'Respons tidak dikenali: '.mb_substr((string) $text, 0, 200);
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 500);
        }

        return ['ok' => $ok, 'provider' => $provider, 'model' => $model, 'latency_ms' => (int) round((microtime(true) - $start) * 1000), 'error' => $error];
    }

    /**
     * Send a prompt and decode the JSON object the model returns.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $system, string $prompt): ?array
    {
        $provider = $this->provider();

        if ($provider === null) {
            return null;
        }

        try {
            $text = $provider === 'gemini'
                ? $this->gemini($system, $prompt)
                : $this->openAi($system, $prompt);
        } catch (Throwable $e) {
            Log::warning("AI request to [{$provider}] failed: {$e->getMessage()}");

            return null;
        }

        $decoded = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', (string) $text)), true);

        return is_array($decoded) ? $decoded : null;
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
