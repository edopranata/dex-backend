<?php

namespace App\Jobs;

use App\Enums\OrderSide;
use App\Models\Signal;
use App\Models\User;
use App\Services\Trading\TradingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The auto-follow paper account buys every new signal the same way a user would from Telegram,
 * so its results show how the app's signals perform with fees and slippage, without cherry-picking.
 */
class FollowSignalAutomatically implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public Signal $signal) {}

    public function handle(TradingService $trading): void
    {
        if (! config('market.autofollow.enabled') || ! $this->signal->refresh()->status->isOpen()) {
            return;
        }

        $account = User::autoFollower();

        // A retried job (e.g. after a worker restart) must not buy the same signal twice.
        if ($account->orders()->where('signal_id', $this->signal->id)->where('side', OrderSide::Buy)->exists()) {
            return;
        }

        try {
            $trading->followSignal($account, $this->signal->load('token'), (float) config('market.autofollow.amount_usd'), limit: true);
        } catch (ValidationException $e) {
            Log::info("Auto-follow melewati sinyal #{$this->signal->id}: ".collect($e->errors())->flatten()->first());
        }
    }
}
