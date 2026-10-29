<?php

namespace App\Listeners;

use App\Services\Notifications\TelegramNotifier;
use Laravel\Horizon\Events\LongWaitDetected;

/**
 * Horizon's long-wait alert goes to the admins' Telegram instead of mail/Slack.
 */
class NotifyAdminsOfLongQueueWait
{
    public function __construct(private TelegramNotifier $notifier) {}

    public function handle(LongWaitDetected $event): void
    {
        $this->notifier->system(
            "Antrean {$event->queue} menunggu terlalu lama",
            "Job tertua sudah menunggu {$event->seconds} detik. Cek /horizon.",
            "horizon-wait:{$event->queue}",
            30,
        );
    }
}
