<?php

namespace App\Services\Notifications;

use RuntimeException;

class TelegramException extends RuntimeException
{
    /**
     * @param  int|null  $status  HTTP status from the Bot API, null for connection errors.
     * @param  int|null  $retryAfter  Seconds Telegram asks us to wait after a 429.
     */
    public function __construct(string $message, public readonly ?int $status = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    /**
     * Rejected for good (bad token, unknown chat, malformed text): retrying will not help.
     */
    public function isPermanent(): bool
    {
        return $this->status !== null && $this->status >= 400 && $this->status < 500 && $this->status !== 429;
    }
}
