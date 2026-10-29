<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\TelegramClient;
use App\Services\Notifications\TelegramException;
use Illuminate\Http\JsonResponse;

class TelegramController extends Controller
{
    /**
     * Chats that sent the bot a message, so the admin can pick the chat ID instead of looking it up.
     */
    public function chats(TelegramClient $telegram): JsonResponse
    {
        try {
            return response()->json(['bot' => $telegram->bot(), 'chats' => $telegram->recentChats()]);
        } catch (TelegramException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function test(TelegramClient $telegram): JsonResponse
    {
        try {
            $bot = $telegram->bot();
            $telegram->send("✅ <b>Tes dari DEX Simulator</b>\nNotifikasi Telegram sudah tersambung.");

            return response()->json(['ok' => true, 'bot' => $bot, 'error' => null]);
        } catch (TelegramException $e) {
            return response()->json(['ok' => false, 'bot' => null, 'error' => $e->getMessage()]);
        }
    }
}
