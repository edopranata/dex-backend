<?php

namespace App\Services\Strategies;

use App\Models\Candle;

/**
 * Closed candles of one DEX token as parallel arrays, oldest first, with the candle patterns the strategies use. Copied
 * from SpotSim's intraday Bars (28 Nov 2026) so the rules read the candles the same way; DEX volume is in USD, so the
 * turnover is the volume.
 */
class Bars
{
    /**
     * @param  list<int>  $t  open time (s)
     * @param  list<float>  $o
     * @param  list<float>  $h
     * @param  list<float>  $l
     * @param  list<float>  $c
     * @param  list<float>  $v
     * @param  list<float>  $turnover  quote volume (USDT)
     */
    public function __construct(
        public array $t = [],
        public array $o = [],
        public array $h = [],
        public array $l = [],
        public array $c = [],
        public array $v = [],
        public array $turnover = [],
    ) {}

    /** Seconds per stored DEX timeframe. */
    public const SECONDS = ['15m' => 900, '1h' => 3600, '4h' => 14400, '1d' => 86400];

    /**
     * The last `count` stored candles that closed before `until` (s).
     */
    public static function load(int $tokenId, string $timeframe, int $count, int $until): self
    {
        $rows = Candle::query()->where('token_id', $tokenId)->where('timeframe', $timeframe)
            ->where('open_time', '<=', $until - self::SECONDS[$timeframe])
            ->orderByDesc('open_time')->limit($count)
            ->get(['open_time', 'open', 'high', 'low', 'close', 'volume'])->reverse()->values();

        $bars = new self;
        foreach ($rows as $row) {
            $bars->t[] = (int) $row->open_time;
            $bars->o[] = (float) $row->open;
            $bars->h[] = (float) $row->high;
            $bars->l[] = (float) $row->low;
            $bars->c[] = (float) $row->close;
            $bars->v[] = (float) $row->volume;
            $bars->turnover[] = (float) $row->volume;
        }

        return $bars;
    }

    /**
     * Groups into candles of `minutes`, aligned to UTC; only complete groups are kept.
     */
    public function aggregate(int $minutes, int $baseSeconds): self
    {
        $size = intdiv($minutes * 60, $baseSeconds);
        $out = new self;
        $group = [];

        foreach ($this->t as $i => $time) {
            $bucket = intdiv($time, $minutes * 60);
            if ($group !== [] && intdiv($this->t[$group[0]], $minutes * 60) !== $bucket) {
                $out->push($this, $group, $size, $minutes);
                $group = [];
            }
            $group[] = $i;
        }
        $out->push($this, $group, $size, $minutes);

        return $out;
    }

    public function count(): int
    {
        return count($this->t);
    }

    /**
     * Whether the last `count` candles follow each other without a missing one. Patterns read neighbours by position,
     * so after a gap "the previous candle" would be an older one (17 Nov 2026 audit); a strategy then waits.
     */
    public function contiguous(int $count, int $seconds): bool
    {
        $last = $this->count() - 1;

        return $count >= 1 && $last - $count + 1 >= 0 && $this->t[$last] - $this->t[$last - $count + 1] === ($count - 1) * $seconds;
    }

    /**
     * Green with a body at least half the range and an upper wick at most a quarter of it.
     */
    public function solid(int $i): bool
    {
        $range = $this->h[$i] - $this->l[$i];

        return $range > 0 && $this->c[$i] > $this->o[$i] && ($this->c[$i] - $this->o[$i]) >= 0.5 * $range && ($this->h[$i] - $this->c[$i]) <= 0.25 * $range;
    }

    public function hammer(int $i): bool
    {
        $range = $this->h[$i] - $this->l[$i];
        $body = abs($this->c[$i] - $this->o[$i]);

        return $range > 0 && (min($this->o[$i], $this->c[$i]) - $this->l[$i]) >= 2 * max($body, 0.05 * $range)
            && ($this->h[$i] - max($this->o[$i], $this->c[$i])) <= 0.25 * $range;
    }

    public function bullishEngulfing(int $i): bool
    {
        return $i > 0 && $this->c[$i] > $this->o[$i] && $this->c[$i - 1] < $this->o[$i - 1]
            && $this->o[$i] <= $this->c[$i - 1] && $this->c[$i] >= $this->o[$i - 1];
    }

    public function shootingStar(int $i): bool
    {
        $range = $this->h[$i] - $this->l[$i];
        $body = abs($this->c[$i] - $this->o[$i]);

        return $range > 0 && ($this->h[$i] - max($this->o[$i], $this->c[$i])) >= 2 * max($body, 0.05 * $range)
            && (min($this->o[$i], $this->c[$i]) - $this->l[$i]) <= 0.25 * $range;
    }

    /**
     * A shooting star or bearish engulfing at i-1, then candle i closing below its low.
     */
    public function bearishConfirmed(int $i): bool
    {
        $k = $i - 1;
        if ($k < 1) {
            return false;
        }
        $engulf = $this->c[$k] < $this->o[$k] && $this->c[$k - 1] > $this->o[$k - 1] && $this->o[$k] >= $this->c[$k - 1] && $this->c[$k] <= $this->o[$k - 1];

        return ($this->shootingStar($k) || $engulf) && $this->c[$i] < $this->l[$k];
    }

    /**
     * Average volume of the `period` candles before i.
     */
    public function volumeAverage(int $i, int $period): float
    {
        $slice = array_slice($this->v, max(0, $i - $period), min($period, $i));

        return $slice === [] ? 0.0 : array_sum($slice) / count($slice);
    }

    /**
     * Pivot lows (or highs): the extreme of the two candles on each side.
     *
     * @return list<int>
     */
    public function pivots(bool $lows): array
    {
        $pivots = [];
        for ($k = 2; $k < $this->count() - 2; $k++) {
            $window = array_slice($lows ? $this->l : $this->h, $k - 2, 5);
            if (($lows ? min($window) : max($window)) === ($lows ? $this->l[$k] : $this->h[$k])) {
                $pivots[] = $k;
            }
        }

        return $pivots;
    }

    /**
     * @param  list<int>  $group
     */
    private function push(self $source, array $group, int $size, int $minutes): void
    {
        if (count($group) !== $size) {
            return;
        }
        $this->t[] = intdiv($source->t[$group[0]], $minutes * 60) * $minutes * 60;
        $this->o[] = $source->o[$group[0]];
        $this->h[] = max(array_map(fn (int $i): float => $source->h[$i], $group));
        $this->l[] = min(array_map(fn (int $i): float => $source->l[$i], $group));
        $this->c[] = $source->c[end($group)];
        $this->v[] = array_sum(array_map(fn (int $i): float => $source->v[$i], $group));
        $this->turnover[] = array_sum(array_map(fn (int $i): float => $source->turnover[$i] ?? 0.0, $group));
    }
}
