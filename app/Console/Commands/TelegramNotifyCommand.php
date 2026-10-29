<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramClient;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:telegram-notify {title : Judul pesan} {detail? : Isi pesan} {--key= : Kirim sekali per jendela waktu untuk kunci ini} {--minutes=60 : Lama jendela (menit)}')]
#[Description('Kirim notifikasi operasional ke Telegram tanpa queue (dipakai watchdog & backup)')]
class TelegramNotifyCommand extends Command
{
    public function handle(TelegramClient $telegram, TelegramNotifier $notifier): int
    {
        if (! $telegram->isActive()) {
            $this->components->warn('Notifikasi Telegram nonaktif.');

            return self::SUCCESS;
        }

        $notifier->system(
            (string) $this->argument('title'),
            (string) ($this->argument('detail') ?? ''),
            $this->option('key') ?: null,
            (int) $this->option('minutes'),
            sync: true,
        );

        return self::SUCCESS;
    }
}
