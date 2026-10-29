<?php

namespace App\Services\Notifications;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin Bot API client. The bot token is part of every request URL, so it is stripped from
 * every error message before it can reach logs or the admin panel.
 */
class TelegramClient
{
    public function isActive(): bool
    {
        return (bool) config('market.telegram.enabled') && filled(config('market.telegram.bot_token'));
    }

    /**
     * Send an HTML-formatted message to a chat.
     *
     * @throws TelegramException
     */
    public function send(string $chatId, string $html, bool $silent = false): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($html, 0, 4000),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'disable_notification' => $silent,
        ]);
    }

    /**
     * Bot identity, cached per token (used for t.me deep links).
     *
     * @return array{username: string, name: string}
     *
     * @throws TelegramException
     */
    public function bot(): array
    {
        $key = 'telegram:bot:'.sha1((string) config('market.telegram.bot_token'));

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $bot = $this->call('getMe');
        $info = ['username' => (string) ($bot['username'] ?? ''), 'name' => (string) ($bot['first_name'] ?? '')];
        Cache::put($key, $info, now()->addDay());

        return $info;
    }

    /**
     * Point Telegram at our webhook. Pending updates are dropped: they were sent before linking was possible.
     *
     * @throws TelegramException
     */
    public function connectWebhook(): void
    {
        $this->call('setWebhook', [
            'url' => $this->webhookUrl(),
            'secret_token' => $this->webhookSecret(),
            'allowed_updates' => ['message'],
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * @return array{url: string, expected_url: string, connected: bool, pending: int, last_error: string|null, last_error_at: string|null}
     *
     * @throws TelegramException
     */
    public function webhookInfo(): array
    {
        $info = $this->call('getWebhookInfo');
        $url = (string) ($info['url'] ?? '');

        return [
            'url' => $url,
            'expected_url' => $this->webhookUrl(),
            'connected' => $url === $this->webhookUrl(),
            'pending' => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => $info['last_error_message'] ?? null,
            'last_error_at' => isset($info['last_error_date']) ? Carbon::createFromTimestamp($info['last_error_date'])->toIso8601String() : null,
        ];
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/telegram/webhook';
    }

    /**
     * Sent by Telegram in X-Telegram-Bot-Api-Secret-Token; changes whenever the bot token or app key changes.
     */
    public function webhookSecret(): string
    {
        return substr(hash_hmac('sha256', 'telegram-webhook|'.config('market.telegram.bot_token'), (string) config('app.key')), 0, 48);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws TelegramException
     */
    private function call(string $method, array $payload = []): mixed
    {
        $token = (string) config('market.telegram.bot_token');

        if ($token === '') {
            throw new TelegramException('Bot token belum diisi.');
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (Throwable $e) {
            throw new TelegramException('Koneksi ke Telegram gagal: '.str_replace($token, '***', $e->getMessage()));
        }

        if ($response->json('ok') !== true) {
            $description = (string) ($response->json('description') ?? "HTTP {$response->status()}");
            $retryAfter = $response->json('parameters.retry_after');

            throw new TelegramException(
                'Telegram menolak: '.str_replace($token, '***', $description),
                $response->status(),
                $retryAfter !== null ? (int) $retryAfter : null,
            );
        }

        return $response->json('result');
    }
}
