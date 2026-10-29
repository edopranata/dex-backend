<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:telegram-daily-summary {--force : Kirim ke semua pengguna sekarang, tanpa melihat jam pilihan mereka}')]
#[Description('Kirim ringkasan harian ke Telegram pengguna yang jam pilihannya (WIB) adalah jam ini')]
class TelegramDailySummaryCommand extends Command
{
    /**
     * Scheduled hourly; each user picks their own hour in Profil → Notifikasi.
     */
    public function handle(TelegramNotifier $notifier): int
    {
        $sent = $notifier->dailySummaries((bool) $this->option('force'));
        $this->components->info("Ringkasan harian diantrikan untuk {$sent} pengguna.");

        return self::SUCCESS;
    }
}
