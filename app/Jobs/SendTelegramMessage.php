<?php

namespace App\Jobs;

use App\Services\Notifications\TelegramClient;
use App\Services\Notifications\TelegramException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Delivered through the queue so a slow or unreachable Telegram never holds up price tracking.
 * Never reported as a failed job itself, otherwise a broken bot would notify about itself forever.
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * @param  bool  $silent  Deliver without sound (quiet hours).
     * @param  array<string, mixed>|null  $replyMarkup  Inline buttons, e.g. the buy buttons under a signal.
     */
    public function __construct(public string $chatId, public string $html, public bool $silent = false, public ?array $replyMarkup = null) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(TelegramClient $telegram): void
    {
        if (! $telegram->isActive()) {
            return;
        }

        try {
            $telegram->send($this->chatId, $this->html, $this->silent, $this->replyMarkup);
        } catch (TelegramException $e) {
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter + 1);

                return;
            }

            if ($e->isPermanent() || $this->attempts() >= $this->tries) {
                Log::warning("Notifikasi Telegram tidak terkirim: {$e->getMessage()}");

                return;
            }

            throw $e;
        }
    }
}
