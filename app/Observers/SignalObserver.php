<?php

namespace App\Observers;

use App\Enums\SignalStatus;
use App\Models\Signal;
use App\Services\Notifications\TelegramNotifier;

class SignalObserver
{
    public function __construct(private TelegramNotifier $notifier) {}

    public function created(Signal $signal): void
    {
        $this->notifier->newSignal($signal);
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

        $this->notifier->signalProgress(
            $signal,
            $previous instanceof SignalStatus ? $previous : SignalStatus::from((string) $previous),
            (int) $signal->getOriginal('max_tp_hit'),
        );
    }
}
