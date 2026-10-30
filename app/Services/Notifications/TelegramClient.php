<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
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
     * Send an HTML-formatted message to a chat, optionally with buttons.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array<string, mixed> The sent message.
     *
     * @throws TelegramException
     */
    public function send(string $chatId, string $html, bool $silent = false, ?array $replyMarkup = null): array
    {
        return (array) $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => mb_substr($html, 0, 4000),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'disable_notification' => $silent,
            'reply_markup' => $replyMarkup,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Replace the text (and buttons) of a message the bot sent earlier.
     *
     * @param  array<string, mixed>|null  $replyMarkup  Null removes the buttons.
     *
     * @throws TelegramException
     */
    public function edit(string $chatId, int $messageId, string $html, ?array $replyMarkup = null): void
    {
        $this->call('editMessageText', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => mb_substr($html, 0, 4000),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $replyMarkup,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Stop the loading spinner on a tapped button, optionally with a short toast or alert.
     *
     * @throws TelegramException
     */
    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert ?: null,
        ], fn ($value): bool => $value !== null));
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
            'allowed_updates' => ['message', 'callback_query'],
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
        // Button taps (callback_query) must be delivered too; an empty list means "all default types".
        $receivesButtons = empty($info['allowed_updates']) || in_array('callback_query', $info['allowed_updates'], true);

        return [
            'url' => $url,
            'expected_url' => $this->webhookUrl(),
            'connected' => $url === $this->webhookUrl() && $receivesButtons,
            'pending' => (int) ($info['pending_update_count'] ?? 0),
            'last_error' => $info['last_error_message'] ?? null,
            'last_error_at' => isset($info['last_error_date']) ? Carbon::createFromTimestamp($info['last_error_date'])->toIso8601String() : null,
        ];
    }

    /**
     * Commands shown in Telegram's "/" menu, for every chat or for one chat.
     *
     * @param  array<string, string>  $commands  Command (without "/") => description.
     *
     * @throws TelegramException
     */
    public function setCommands(array $commands, ?string $chatId = null): void
    {
        $this->call('setMyCommands', array_filter([
            'commands' => collect($commands)->map(fn (string $description, string $command): array => ['command' => $command, 'description' => $description])->values()->all(),
            'scope' => $chatId !== null ? ['type' => 'chat', 'chat_id' => $chatId] : null,
        ]));
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
            // Connections to Telegram are now and then reset mid-request: retry those quickly.
            $response = Http::timeout(15)
                ->retry([200, 1000], when: fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
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
