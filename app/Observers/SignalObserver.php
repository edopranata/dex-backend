<?php

namespace App\Observers;

use App\Enums\SignalStatus;
use App\Jobs\FollowSignalAutomatically;
use App\Models\Signal;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Trading\TradingService;

class SignalObserver
{
    public function __construct(private TelegramNotifier $notifier, private TradingService $trading) {}

    public function created(Signal $signal): void
    {
        $this->notifier->newSignal($signal);

        if (config('market.autofollow.enabled')) {
            FollowSignalAutomatically::dispatch($signal);
        }
    }

    /**
     * During "updated" the original attributes still hold the values from before the save.
     */
    public function updated(Signal $signal): void
    {
        if (! $signal->wasChanged(['status', 'max_tp_hit'])) {
            return;
        }

        $previous = $signal->getOriginal('status');
        $previous = $previous instanceof SignalStatus ? $previous : SignalStatus::from((string) $previous);

        if ($previous->isOpen() && ! $signal->status->isOpen()) {
            $this->trading->cancelPendingSignalBuys($signal);
        }

        $this->notifier->signalProgress($signal, $previous, (int) $signal->getOriginal('max_tp_hit'));
    }
}
