<?php

namespace App\Services\Analysis;

use App\Models\Candle;
use App\Models\ShadowSignal;
use App\Models\Signal;
use Illuminate\Support\Collection;

/**
 * Follows untraded shadow signals on 15m candles, the way the 12 Nov replay did: entry once a candle reaches the top
 * of the entry zone within the entry window, the stop checked first in each candle, the stop moved to the entry after
 * TP1, a third sold at each target, and a close at the time limit. Like a real signal, a token has one open shadow per
 * rule at a time, so later ones while it is open count as overlap and are left out of the results.
 */
class ShadowSignalEvaluator
{
    private const CANDLE = 900;

    public function __construct(private SignalResult $results) {}

    /**
     * @return array{evaluated: int, still_open: int}
     */
    public function run(): array
    {
        $evaluated = 0;
        $open = 0;

        ShadowSignal::query()->where('status', ShadowSignal::PENDING)->orderBy('created_at')->orderBy('id')->get()
            ->groupBy(fn (ShadowSignal $shadow): string => $shadow->token_id.':'.$shadow->rule)
            ->each(function (Collection $shadows) use (&$evaluated, &$open): void {
                foreach ($shadows as $shadow) {
                    $busyUntil = $this->busyUntil($shadow);

                    if ($busyUntil === false) {
                        // An earlier one on this token is still being followed.
                        $open += $shadows->count();

                        return;
                    }

                    if ($busyUntil !== null && $shadow->created_at->timestamp < $busyUntil) {
                        $shadow->update(['status' => ShadowSignal::OVERLAP]);
                        $evaluated++;

                        continue;
                    }

                    if (! $this->follow($shadow)) {
                        $open++;

                        return;
                    }

                    $evaluated++;
                }
            });

        return ['evaluated' => $evaluated, 'still_open' => $open];
    }

    /**
     * When the previous followed shadow of the same token and rule ended: null if there is none, false if it is
     * still open.
     */
    private function busyUntil(ShadowSignal $shadow): int|false|null
    {
        $previous = ShadowSignal::query()
            ->where('token_id', $shadow->token_id)
            ->where('rule', $shadow->rule)
            ->where('id', '!=', $shadow->id)
            ->where('status', '!=', ShadowSignal::OVERLAP)
            ->where(fn ($query) => $query->where('created_at', '<', $shadow->created_at)
                ->orWhere(fn ($same) => $same->where('created_at', $shadow->created_at)->where('id', '<', $shadow->id)))
            ->latest('created_at')->latest('id')
            ->first();

        return match (true) {
            $previous === null => null,
            $previous->status === ShadowSignal::PENDING => false,
            default => $previous->closed_at?->timestamp,
        };
    }

    /**
     * Settle one shadow if its candles show the outcome; false while the outcome is not known yet.
     */
    private function follow(ShadowSignal $shadow): bool
    {
        $config = config('market.analysis');
        $created = $shadow->created_at->timestamp;
        $entryUntil = $created + (int) $config['signal_entry_expiry_hours'] * 3600;
        $targets = [$shadow->take_profit_1, $shadow->take_profit_2, $shadow->take_profit_3];
        $entry = null;
        $enteredAt = null;
        $tpHit = 0;

        $candles = Candle::query()->where('token_id', $shadow->token_id)->where('timeframe', '15m')
            ->where('open_time', '>=', intdiv($created, self::CANDLE) * self::CANDLE + self::CANDLE)
            ->where('open_time', '<', $created + ((int) $config['signal_entry_expiry_hours'] + (int) $config['signal_max_hours']) * 3600 + self::CANDLE)
            ->orderBy('open_time')
            ->get(['open_time', 'open', 'high', 'low', 'close']);

        foreach ($candles as $candle) {
            $reachTargets = true;

            if ($entry === null) {
                if ($candle->open_time >= $entryUntil || $candle->open <= $shadow->stop_loss) {
                    return $this->settle($shadow, ShadowSignal::NOT_ENTERED, $candle->open_time);
                }

                if ($candle->open <= $shadow->entry_high) {
                    $entry = (float) $candle->open;
                } elseif ($candle->low <= $shadow->entry_high) {
                    $entry = $shadow->entry_high;
                    $reachTargets = false;
                } else {
                    continue;
                }

                $enteredAt = (int) $candle->open_time;
            }

            $stop = $tpHit > 0 ? $entry : $shadow->stop_loss;

            if ($candle->low <= $stop) {
                return $this->settle($shadow, $tpHit > 0 ? ShadowSignal::TAKE_PROFIT : ShadowSignal::STOP_LOSS, $candle->open_time + self::CANDLE, $entry, $enteredAt, min((float) $candle->open, $stop), $tpHit);
            }

            if ($reachTargets) {
                while ($tpHit < 3 && $candle->high >= $targets[$tpHit]) {
                    $tpHit++;
                }

                if ($tpHit === 3) {
                    return $this->settle($shadow, ShadowSignal::TAKE_PROFIT, $candle->open_time + self::CANDLE, $entry, $enteredAt, $targets[2], 3);
                }
            }

            if ($candle->open_time + self::CANDLE >= $enteredAt + (int) $config['signal_max_hours'] * 3600) {
                return $this->settle($shadow, ShadowSignal::EXPIRED, $candle->open_time + self::CANDLE, $entry, $enteredAt, (float) $candle->close, $tpHit);
            }
        }

        // No entry and the window is over (candles stopped, e.g. the token left the Market).
        if ($entry === null && now()->timestamp >= $entryUntil + 2 * 3600) {
            return $this->settle($shadow, ShadowSignal::NOT_ENTERED, $entryUntil);
        }

        return false;
    }

    private function settle(ShadowSignal $shadow, string $status, int $closedAt, ?float $entry = null, ?int $enteredAt = null, ?float $exit = null, int $tpHit = 0): bool
    {
        $result = ['net_r' => null, 'cost_r' => null];

        if ($entry !== null && $exit !== null) {
            $result = $this->results->calculate(new Signal([
                'entry_price' => $entry,
                'stop_loss' => $shadow->stop_loss,
                'take_profit_1' => $shadow->take_profit_1,
                'take_profit_2' => $shadow->take_profit_2,
                'take_profit_3' => $shadow->take_profit_3,
            ]), $exit, $tpHit, $shadow->liquidity_usd);
        }

        $shadow->update([
            'status' => $status,
            'entry_price' => $entry,
            'entered_at' => $enteredAt === null ? null : now()->setTimestamp($enteredAt),
            'exit_price' => $exit,
            'max_tp_hit' => $tpHit,
            'net_r' => $result['net_r'],
            'cost_r' => $result['cost_r'],
            'closed_at' => now()->setTimestamp($closedAt),
        ]);

        return true;
    }
}
