<?php

namespace App\Observers;

use App\Models\WatchlistEvent;
use App\Services\Notifications\TelegramNotifier;

class WatchlistEventObserver
{
    public function __construct(private TelegramNotifier $notifier) {}

    public function created(WatchlistEvent $event): void
    {
        $this->notifier->watchlist($event);
    }
}
