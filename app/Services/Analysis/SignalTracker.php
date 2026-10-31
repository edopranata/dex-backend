<?php

namespace App\Services\Analysis;

use App\Enums\SignalStatus;
use App\Models\Signal;

/**
 * Follows each signal like a disciplined trader would: enter in the zone, take 1/3 at each
 * target, move the stop to breakeven after TP1, and record the result in R multiples.
 */
class SignalTracker
{
    public function __construct(private SignalResult $results) {}

    public function track(Signal $signal, float $price): void
    {
        match ($signal->status) {
            SignalStatus::Pending => $this->trackPending($signal, $price),
            SignalStatus::Active => $this->trackActive($signal, $price),
            default => null,
        };
    }

    private function trackPending(Signal $signal, float $price): void
    {
        if ($price <= $signal->stop_loss) {
            $signal->update(['status' => SignalStatus::Invalidated, 'closed_at' => now()]);

            return;
        }

        if ($price <= $signal->entry_high) {
            $signal->update(['status' => SignalStatus::Active, 'entry_price' => $price, 'entered_at' => now()]);

            return;
        }

        if ($signal->created_at->lt(now()->subHours(config('market.analysis.signal_entry_expiry_hours')))) {
            $signal->update(['status' => SignalStatus::Expired, 'closed_at' => now()]);
        }
    }

    private function trackActive(Signal $signal, float $price): void
    {
        $targets = $signal->takeProfits();
        $maxTpHit = (int) $signal->max_tp_hit;

        while ($maxTpHit < 3 && $price >= $targets[$maxTpHit]) {
            $maxTpHit++;
        }

        if ($maxTpHit === 3) {
            $this->close($signal, SignalStatus::TakeProfit, $price, $maxTpHit);

            return;
        }

        $stop = $maxTpHit > 0 ? $signal->entry_price : $signal->stop_loss;

        if ($price <= $stop) {
            $this->close($signal, $maxTpHit > 0 ? SignalStatus::TakeProfit : SignalStatus::StopLoss, $price, $maxTpHit);

            return;
        }

        if ($signal->entered_at->lt(now()->subHours(config('market.analysis.signal_max_hours')))) {
            $this->close($signal, SignalStatus::Expired, $price, $maxTpHit);

            return;
        }

        if ($maxTpHit !== $signal->max_tp_hit) {
            $signal->update(['max_tp_hit' => $maxTpHit]);
        }
    }

    private function close(Signal $signal, SignalStatus $status, float $price, int $maxTpHit): void
    {
        $result = $this->results->calculate($signal, $price, $maxTpHit, $signal->token?->liquidity_usd);

        $signal->update([
            'status' => $status,
            'max_tp_hit' => $maxTpHit,
            'exit_price' => $price,
            'result_r' => $result['gross_r'],
            'net_r' => $result['net_r'],
            'cost_r' => $result['cost_r'],
            'closed_at' => now(),
        ]);
    }
}
