<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Admin\HorizonStatus;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Answers messages sent to the bot (via webhook). Replies are returned to the webhook caller,
 * which hands them back to Telegram in the HTTP response.
 */
class TelegramBot
{
    /**
     * Shown in Telegram's "/" menu for everyone (setMyCommands).
     *
     * @var array<string, string>
     */
    public const COMMANDS = [
        'status' => 'Ringkasan portofolio Anda',
        'help' => 'Daftar perintah',
        'stop' => 'Berhenti menerima notifikasi',
    ];

    /**
     * Extra commands in the menu of admins' chats.
     *
     * @var array<string, string>
     */
    public const ADMIN_COMMANDS = [
        'horizon' => 'Status antrean job (admin)',
    ];

    public function __construct(
        private TelegramLinker $linker,
        private TelegramNotifier $notifier,
        private TelegramBuyFlow $buyFlow,
        private HorizonStatus $horizonStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     * @return array{chat_id: string, text: string, reply_markup?: array<string, mixed>}|null
     */
    public function handle(array $update): ?array
    {
        if (is_array($update['callback_query'] ?? null)) {
            $this->buyFlow->handleCallback($update['callback_query']);

            return null;
        }

        $message = $update['message'] ?? null;

        if (! is_array($message) || ! isset($message['chat']['id'], $message['text'])) {
            return null;
        }

        $chatId = (string) $message['chat']['id'];

        // An amount typed after "Nominal lain" (commands still work and cancel nothing).
        if (! str_starts_with(trim((string) $message['text']), '/') && ($reply = $this->buyFlow->handleAmount($chatId, (string) $message['text'])) !== null) {
            return $reply;
        }

        $username = $message['from']['username'] ?? null;
        [$command, $argument] = $this->parse((string) $message['text']);

        $reply = match (true) {
            $command === '/start' && $argument !== '' => $this->link($chatId, $argument, $username),
            $command === '/status' => $this->status($chatId),
            $command === '/stop' => $this->stop($chatId),
            $command === '/horizon' => $this->horizon($chatId),
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

        if ($user?->is_admin) {
            rescue(fn () => app(TelegramClient::class)->setCommands(self::COMMANDS + self::ADMIN_COMMANDS, $chatId), report: false);
        }

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
        $users = $this->linkedUsers($chatId);

        if ($users === []) {
            return $this->notLinked();
        }

        $commands = self::COMMANDS + ($this->hasAdmin($users) ? self::ADMIN_COMMANDS : []);

        return "🤖 <b>DEX Simulator</b>\n".collect($commands)->map(fn (string $description, string $command): string => "/{$command} — {$description}")->implode("\n");
    }

    /**
     * Queue health for admins: Horizon status, waiting jobs per queue, throughput and failures.
     */
    private function horizon(string $chatId): string
    {
        $users = $this->linkedUsers($chatId);

        if ($users === []) {
            return $this->notLinked();
        }

        if (! $this->hasAdmin($users)) {
            return 'Perintah /horizon hanya untuk admin.';
        }

        try {
            $status = $this->horizonStatus->summary();
        } catch (Throwable $e) {
            return '⚠️ Status Horizon tidak bisa dibaca: '.e(mb_substr($e->getMessage(), 0, 200));
        }

        $lines = ['🧭 <b>Horizon: '.match ($status['status']) {
            'running' => 'berjalan ✅',
            'paused' => 'dijeda ⏸',
            default => 'tidak aktif ❌',
        }.'</b>'];

        foreach ($status['queues'] as $queue) {
            $lines[] = sprintf('• %s: %d menunggu · tunggu ±%d dtk · %d proses', e($queue['name']), $queue['length'], $queue['wait'], $queue['processes']);
        }

        $lines[] = sprintf('Job/menit: %s · gagal 24 jam: %d', $status['jobs_per_minute'], $status['failed_24h']);
        $lines[] = '<a href="'.e(rtrim((string) config('app.url'), '/').'/horizon').'">Buka dashboard Horizon</a>';

        return implode("\n", $lines);
    }

    /**
     * Put the bot's commands in Telegram's "/" menu: the common list for everyone, plus admin
     * commands in each linked admin's chat.
     */
    public static function syncCommands(TelegramClient $telegram): void
    {
        $telegram->setCommands(self::COMMANDS);

        User::query()->where('is_admin', true)->whereNotNull('telegram_chat_id')->pluck('telegram_chat_id')->unique()
            ->each(fn (string $chatId) => $telegram->setCommands(self::COMMANDS + self::ADMIN_COMMANDS, $chatId));
    }

    /**
     * @param  list<User>  $users
     */
    private function hasAdmin(array $users): bool
    {
        return collect($users)->contains(fn (User $user): bool => (bool) $user->is_admin);
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
