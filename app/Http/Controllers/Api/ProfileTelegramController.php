<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifications\TelegramClient;
use App\Services\Notifications\TelegramException;
use App\Services\Notifications\TelegramLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileTelegramController extends Controller
{
    /**
     * A one-time code (and t.me deep link) the user sends to the bot to link their chat.
     */
    public function link(Request $request, TelegramClient $telegram, TelegramLinker $linker): JsonResponse
    {
        abort_unless($telegram->isActive(), 422, 'Notifikasi Telegram belum diaktifkan admin.');

        try {
            $bot = $telegram->bot();
        } catch (TelegramException $e) {
            abort(422, $e->getMessage());
        }

        $issued = $linker->issue($request->user());

        return response()->json([
            'code' => $issued['code'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'bot_username' => $bot['username'],
            'deep_link' => "https://t.me/{$bot['username']}?start={$issued['code']}",
        ]);
    }

    public function destroy(Request $request, TelegramLinker $linker): JsonResponse
    {
        $linker->unlink($request->user());

        return response()->json(['message' => 'Telegram diputus.']);
    }

    public function test(Request $request, TelegramClient $telegram): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasTelegram(), 422, 'Telegram belum terhubung.');

        try {
            $telegram->send($user->telegram_chat_id, "✅ <b>Tes dari DEX Simulator</b>\nHalo ".e($user->name).', notifikasi sudah tersambung.');
        } catch (TelegramException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'error' => null]);
    }
}
