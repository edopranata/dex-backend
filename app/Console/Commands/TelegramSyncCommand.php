<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramBot;
use App\Services\Notifications\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:telegram-sync')]
#[Description('Pasang ulang webhook bot Telegram dan daftar perintahnya (menu "/" di Telegram)')]
class TelegramSyncCommand extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        if (! $telegram->isActive()) {
            $this->components->warn('Telegram nonaktif atau bot token kosong; tidak ada yang disinkronkan.');

            return self::SUCCESS;
        }

        $telegram->connectWebhook();
        TelegramBot::syncCommands($telegram);
        $this->components->info('Webhook dan daftar perintah bot sudah disinkronkan.');

        return self::SUCCESS;
    }
}
