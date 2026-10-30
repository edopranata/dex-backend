<?php

namespace App\Services\Analysis;

use App\Models\Analysis;
use App\Models\AnalysisEvaluation;
use App\Models\Candle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Measures what the price did after every analysis (1h, 4h, 24h and the 24h range), so the
 * recommendations and the score itself can be judged on all of them, not only on signals.
 */
class AnalysisEvaluator
{
    /**
     * Candles are synced every 15 minutes; give them time to arrive before reading a horizon.
     */
    private const SYNC_LAG_MINUTES = 30;

    /**
     * After this, a token that stopped being synced (left the Market) is evaluated with what exists.
     */
    private const GIVE_UP_HOURS = 48;

    /**
     * Start tracking recent analyses that are not tracked yet.
     */
    public function record(int $days = 3): int
    {
        $created = 0;

        Analysis::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->whereDoesntHave('evaluation')
            ->chunkById(500, function (Collection $analyses) use (&$created): void {
                $created += AnalysisEvaluation::query()->insertOrIgnore($analyses->map(fn (Analysis $analysis): array => [
                    'analysis_id' => $analysis->id,
                    'token_id' => $analysis->token_id,
                    'recommendation' => $analysis->recommendation->value,
                    'final_score' => $analysis->final_score,
                    'scores' => json_encode([
                        'technical' => $analysis->technical_score,
                        'onchain' => $analysis->onchain_score,
                        'whale' => $analysis->whale_score,
                        'sentiment' => $analysis->sentiment_score,
                        'fundamental' => $analysis->fundamental_score,
                    ]),
                    'price' => $analysis->price,
                    'analyzed_at' => $analysis->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });

        return $created;
    }

    /**
     * Fill every horizon that has passed. Returns how many evaluations changed.
     */
    public function evaluate(): int
    {
        $updated = 0;

        AnalysisEvaluation::query()
            ->whereNull('completed_at')
            ->where('analyzed_at', '<=', now()->subHour()->subMinutes(self::SYNC_LAG_MINUTES))
            ->chunkById(200, function (Collection $evaluations) use (&$updated): void {
                foreach ($evaluations as $evaluation) {
                    $updated += (int) $this->evaluateOne($evaluation);
                }
            });

        return $updated;
    }

    private function evaluateOne(AnalysisEvaluation $evaluation): bool
    {
        $changes = [];

        foreach (AnalysisEvaluation::HORIZONS as $hours => $column) {
            $due = $evaluation->analyzed_at->copy()->addHours($hours);

            if ($evaluation->{$column} !== null || $due->copy()->addMinutes(self::SYNC_LAG_MINUTES)->isFuture()) {
                continue;
            }

            $price = $this->priceAt($evaluation->token_id, $due);

            if ($price !== null) {
                $changes[$column] = $this->percent($evaluation->price, $price);
            }
        }

        $dayPassed = $evaluation->analyzed_at->copy()->addDay()->addMinutes(self::SYNC_LAG_MINUTES)->isPast();

        if ($dayPassed && $evaluation->max_up_24h === null && ($range = $this->range($evaluation->token_id, $evaluation->analyzed_at)) !== null) {
            $changes['max_up_24h'] = $this->percent($evaluation->price, $range['high']);
            $changes['max_down_24h'] = $this->percent($evaluation->price, $range['low']);
        }

        $complete = ($changes['return_24h'] ?? $evaluation->return_24h) !== null && ($changes['max_up_24h'] ?? $evaluation->max_up_24h) !== null;

        if ($complete || $evaluation->analyzed_at->lt(now()->subHours(self::GIVE_UP_HOURS))) {
            $changes['completed_at'] = now();
        }

        if ($changes === []) {
            return false;
        }

        $evaluation->update($changes);

        return true;
    }

    /**
     * Close of the candle that contains the moment: 15m when available, else 1h.
     */
    private function priceAt(int $tokenId, Carbon $at): ?float
    {
        foreach (['15m' => 900, '1h' => 3600] as $timeframe => $seconds) {
            $close = Candle::query()
                ->where('token_id', $tokenId)
                ->where('timeframe', $timeframe)
                ->where('open_time', intdiv($at->getTimestamp(), $seconds) * $seconds)
                ->value('close');

            if ($close !== null) {
                return (float) $close;
            }
        }

        return null;
    }

    /**
     * Highest high and lowest low in the 24 hours after the analysis.
     *
     * @return array{high: float, low: float}|null
     */
    private function range(int $tokenId, Carbon $from): ?array
    {
        foreach (['15m' => 900, '1h' => 3600] as $timeframe => $seconds) {
            $start = intdiv($from->getTimestamp(), $seconds) * $seconds;
            $row = Candle::query()
                ->where('token_id', $tokenId)
                ->where('timeframe', $timeframe)
                ->whereBetween('open_time', [$start, $start + 86_400])
                ->selectRaw('MAX(high) as high, MIN(low) as low')
                ->first();

            if ($row?->high !== null) {
                return ['high' => (float) $row->high, 'low' => (float) $row->low];
            }
        }

        return null;
    }

    private function percent(float $from, float $to): float
    {
        return $from > 0 ? round(($to / $from - 1) * 100, 4) : 0.0;
    }
}
