<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Answers messages sent to the bot (via webhook). Replies are returned to the webhook caller,
 * which hands them back to Telegram in the HTTP response.
 */
class TelegramBot
{
    public function __construct(private TelegramLinker $linker, private TelegramNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $update
     * @return array{chat_id: string, text: string}|null
     */
    public function handle(array $update): ?array
    {
        $message = $update['message'] ?? null;

        if (! is_array($message) || ! isset($message['chat']['id'], $message['text'])) {
            return null;
        }

        $chatId = (string) $message['chat']['id'];
        $username = $message['from']['username'] ?? null;
        [$command, $argument] = $this->parse((string) $message['text']);

        $reply = match (true) {
            $command === '/start' && $argument !== '' => $this->link($chatId, $argument, $username),
            $command === '/status' => $this->status($chatId),
            $command === '/stop' => $this->stop($chatId),
            $command === null && preg_match('/^[A-Za-z0-9]{8}$/', $argument) === 1 => $this->link($chatId, $argument, $username),
            default => $this->help($chatId),
        };

        return ['chat_id' => $chatId, 'text' => $reply];
    }

    /**
     * "/start@DexBot ABC" -> ["/start", "ABC"]; plain text -> [null, text].
     *
     * @return array{0: string|null, 1: string}
     */
    private function parse(string $text): array
    {
        $text = trim($text);

        if (! str_starts_with($text, '/')) {
            return [null, $text];
        }

        [$command, $argument] = array_pad(preg_split('/\s+/', $text, 2), 2, '');

        return [strtolower(explode('@', $command)[0]), trim($argument)];
    }

    private function link(string $chatId, string $code, ?string $username): string
    {
        if (! RateLimiter::attempt("telegram-link:{$chatId}", 10, fn (): bool => true, 3600)) {
            return 'Terlalu banyak percobaan. Coba lagi dalam 1 jam.';
        }

        $user = $this->linker->redeem($code, $chatId, $username);

        if ($user === null) {
            return "❌ Kode tidak valid atau kedaluwarsa.\nBuat kode baru di DEX Simulator → Profil → Telegram.";
        }

        return "✅ <b>Terhubung dengan akun {$this->name($user)}</b>\nNotifikasi akan dikirim ke chat ini. Atur jenisnya di Profil → Notifikasi.\n\n/status — ringkasan portofolio\n/stop — putuskan hubungan";
    }

    private function status(string $chatId): string
    {
        $users = $this->linkedUsers($chatId);

        if ($users === []) {
            return $this->notLinked();
        }

        $parts = array_map(fn (User $user): string => "👤 <b>{$this->name($user)}</b>\n".implode("\n", $this->notifier->portfolioLines($user)), $users);

        return implode("\n\n", $parts);
    }

    private function stop(string $chatId): string
    {
        $users = $this->linkedUsers($chatId);

        foreach ($users as $user) {
            $this->linker->unlink($user);
        }

        return $users === [] ? $this->notLinked() : '🔕 Hubungan diputus. Chat ini tidak akan menerima notifikasi lagi.';
    }

    private function help(string $chatId): string
    {
        if ($this->linkedUsers($chatId) === []) {
            return $this->notLinked();
        }

        return "🤖 <b>DEX Simulator</b>\n/status — ringkasan portofolio\n/stop — berhenti menerima notifikasi";
    }

    private function notLinked(): string
    {
        return "Chat ini belum terhubung.\nBuka DEX Simulator → Profil → Telegram, klik <b>Hubungkan</b>, lalu kirim kode yang muncul ke sini.";
    }

    /**
     * @return list<User>
     */
    private function linkedUsers(string $chatId): array
    {
        return User::query()->where('telegram_chat_id', $chatId)->get()->all();
    }

    private function name(User $user): string
    {
        return e($user->name);
    }
}
