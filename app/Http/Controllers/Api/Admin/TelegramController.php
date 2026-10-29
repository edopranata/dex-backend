<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\TelegramClient;
use App\Services\Notifications\TelegramException;
use Illuminate\Http\JsonResponse;

class TelegramController extends Controller
{
    /**
     * Bot identity, webhook health and how many users linked a chat.
     */
    public function show(TelegramClient $telegram): JsonResponse
    {
        return response()->json($this->status($telegram));
    }

    public function connect(TelegramClient $telegram): JsonResponse
    {
        try {
            $telegram->connectWebhook();
        } catch (TelegramException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->status($telegram));
    }

    /**
     * @return array{active: bool, bot: array{username: string, name: string}|null, webhook: array<string, mixed>|null, linked_users: int, error: string|null}
     */
    private function status(TelegramClient $telegram): array
    {
        $status = ['active' => $telegram->isActive(), 'bot' => null, 'webhook' => null, 'linked_users' => User::query()->whereNotNull('telegram_chat_id')->count(), 'error' => null];

        if (blank(config('market.telegram.bot_token'))) {
            return $status;
        }

        try {
            $status['bot'] = $telegram->bot();
            $status['webhook'] = $telegram->webhookInfo();
        } catch (TelegramException $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }
}
