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
 * The auto-follow paper accounts buy new signals the way a user would from Telegram, within their risk
 * rules, so their results show how the signals perform as a portfolio with fees and slippage.
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

        foreach (User::AUTO_FOLLOW_ACCOUNTS as $key => $definition) {
            if (in_array($this->signal->recommendation->value, $definition['recommendations'], true)) {
                $this->follow(User::autoFollower($key), $key, $trading);
            }
        }
    }

    private function follow(User $account, string $key, TradingService $trading): void
    {
        // A retried job (e.g. after a worker restart) must not buy the same signal twice.
        if ($account->orders()->where('signal_id', $this->signal->id)->where('side', OrderSide::Buy)->exists()) {
            return;
        }

        $risk = app(AutoFollowRisk::class);
        $signal = $this->signal->load('token');

        // Signals can arrive together: one at a time per account, so the position limit holds.
        Cache::lock("autofollow:{$key}", 30)->block(20, function () use ($risk, $account, $key, $signal, $trading): void {
            if ($risk->dailyLossReached($account)) {
                Log::info("Auto-follow {$key} melewati sinyal #{$signal->id}: batas rugi harian tercapai.");

                return;
            }

            if (! $risk->hasRoom($account)) {
                Log::info("Auto-follow {$key} melewati sinyal #{$signal->id}: batas posisi terbuka tercapai.");

                return;
            }

            $amount = $risk->amountFor($account, $signal);

            if ($amount === null) {
                Log::info("Auto-follow {$key} melewati sinyal #{$signal->id}: ukuran order di bawah minimum.");

                return;
            }

            try {
                $trading->followSignal($account, $signal, $amount, limit: true);
            } catch (ValidationException $e) {
                Log::info("Auto-follow {$key} melewati sinyal #{$signal->id}: ".collect($e->errors())->flatten()->first());
            }
        });
    }
}
