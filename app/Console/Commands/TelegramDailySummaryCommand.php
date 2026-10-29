<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:telegram-daily-summary {--force : Kirim walau belum jamnya}')]
#[Description('Kirim ringkasan sinyal 24 jam ke Telegram pada jam yang diatur di Admin')]
class TelegramDailySummaryCommand extends Command
{
    /**
     * Scheduled hourly; sends only in the hour chosen in the admin panel so the time stays editable there.
     */
    public function handle(TelegramNotifier $notifier): int
    {
        if (! $this->option('force') && now('Asia/Jakarta')->hour !== (int) config('market.telegram.daily_summary_hour')) {
            return self::SUCCESS;
        }

        $notifier->dailySummary();

        return self::SUCCESS;
    }
}
