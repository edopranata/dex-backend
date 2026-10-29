<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifications\TelegramBot;
use App\Services\Notifications\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelegramWebhookController extends Controller
{
    /**
     * Telegram calls this for every message to the bot. The reply rides back in the response
     * body (a Bot API method call), so no extra outbound request is needed.
     */
    public function __invoke(Request $request, TelegramClient $telegram, TelegramBot $bot): JsonResponse|Response
    {
        if (! $telegram->isActive() || ! hash_equals($telegram->webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->noContent(403);
        }

        $reply = $bot->handle($request->all());

        if ($reply === null) {
            return response()->noContent();
        }

        return response()->json([
            'method' => 'sendMessage',
            ...$reply,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }
}
