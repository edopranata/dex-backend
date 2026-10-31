<?php

namespace App\Jobs;

use App\Enums\OrderSide;
use App\Models\Signal;
use App\Models\User;
use App\Services\Trading\AutoFollowRisk;
use App\Services\Trading\TradingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The auto-follow paper account buys new signals the way a user would from Telegram, within its risk
 * rules, so its results show how the signals perform as a portfolio with fees and slippage.
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

        $risk = app(AutoFollowRisk::class);
        $signal = $this->signal->load('token');

        // Signals can arrive together: one at a time, so the position limit holds.
        Cache::lock('autofollow', 30)->block(20, function () use ($risk, $account, $signal, $trading): void {
            if ($risk->dailyLossReached($account)) {
                Log::info("Auto-follow melewati sinyal #{$signal->id}: batas rugi harian tercapai.");

                return;
            }

            if (! $risk->hasRoom($account)) {
                Log::info("Auto-follow melewati sinyal #{$signal->id}: batas posisi terbuka tercapai.");

                return;
            }

            $amount = $risk->amountFor($account, $signal);

            if ($amount === null) {
                Log::info("Auto-follow melewati sinyal #{$signal->id}: ukuran order di bawah minimum.");

                return;
            }

            try {
                $trading->followSignal($account, $signal, $amount, limit: true);
            } catch (ValidationException $e) {
                Log::info("Auto-follow melewati sinyal #{$signal->id}: ".collect($e->errors())->flatten()->first());
            }
        });
    }
}
