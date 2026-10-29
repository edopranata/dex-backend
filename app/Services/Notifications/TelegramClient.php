<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin Bot API client. The bot token is part of every request URL, so it is stripped from
 * every error message before it can reach logs or the admin panel.
 */
class TelegramClient
{
    public function isConfigured(): bool
    {
        return filled(config('market.telegram.bot_token')) && filled(config('market.telegram.chat_id'));
    }

    /**
     * Send an HTML-formatted message to the configured chat (or the given one).
     *
     * @throws TelegramException
     */
    public function send(string $html, ?string $chatId = null, bool $silent = false): void
    {
        $chatId ??= (string) config('market.telegram.chat_id');

        if ($chatId === '') {
            throw new TelegramException('Chat ID belum diisi.');
        }

        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => mb_substr($html, 0, 4000),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'disable_notification' => $silent,
        ]);
    }

    /**
     * @return array{username: string, name: string}
     *
     * @throws TelegramException
     */
    public function bot(): array
    {
        $bot = $this->call('getMe');

        return ['username' => (string) ($bot['username'] ?? ''), 'name' => (string) ($bot['first_name'] ?? '')];
    }

    /**
     * Chats that messaged the bot recently, newest first, so the admin can pick a chat ID.
     *
     * @return list<array{id: string, type: string, title: string}>
     *
     * @throws TelegramException
     */
    public function recentChats(): array
    {
        $chats = [];

        foreach (array_reverse($this->call('getUpdates', ['limit' => 100]) ?? []) as $update) {
            $chat = $update['message']['chat'] ?? $update['channel_post']['chat'] ?? $update['my_chat_member']['chat'] ?? null;

            if ($chat === null || isset($chats[$chat['id']])) {
                continue;
            }

            $chats[$chat['id']] = [
                'id' => (string) $chat['id'],
                'type' => (string) $chat['type'],
                'title' => (string) ($chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? $chat['id'])),
            ];
        }

        return array_values($chats);
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
