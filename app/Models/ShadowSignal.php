<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signal that was not traded, kept to measure a rule (v6, 12 Nov 2026): every Strong Buy held back because the top
 * ten holders own too much, and every real signal as the comparison. Both groups are followed on 15m candles by the
 * same method, so the difference shows what the rule removed. Separate from `signals` on purpose: nothing here blocks
 * a real signal, places an order, or sends a notification.
 */
#[Fillable([
    'token_id', 'analysis_id', 'signal_id', 'rule', 'recommendation', 'strategy_version', 'metric',
    'entry_low', 'entry_high', 'stop_loss', 'take_profit_1', 'take_profit_2', 'take_profit_3', 'liquidity_usd',
    'status', 'entry_price', 'exit_price', 'max_tp_hit', 'net_r', 'cost_r', 'entered_at', 'closed_at',
])]
class ShadowSignal extends Model
{
    /** A Strong Buy the holder rule held back. */
    public const HELD_BY_HOLDERS = 'top10_holders';

    /** A real signal, followed the same way as the comparison. */
    public const ACCEPTED = 'accepted';

    /**
     * A Buy, which no longer makes a signal since v5, with the Fear & Greed value as the metric (15 Nov 2026): kept to
     * judge, once BTC turns up, whether Buy should trade again and at which market sentiment.
     */
    public const BUY_WATCH = 'buy_watch';

    /**
     * v7 (21 Nov 2026): a Strong Buy at or above `market.analysis.strong_buy_max_score`, which makes no signal, with
     * its score as the metric.
     */
    public const HIGH_SCORE = 'high_score';

    /**
     * v8 (26 Nov 2026): a Strong Buy whose previous analysis was not Buy or Strong Buy, which makes no signal, with its
     * score as the metric.
     */
    public const FIRST_BUY = 'first_buy';

    /**
     * v7 running beside v8 (owner, 27 Nov 2026): every Strong Buy the v7 rule would trade (trade levels, score under
     * `market.analysis.strong_buy_max_score`), with or without the v8 confirmation, with its score as the metric.
     * Followed with the v7 exit (the first later analysis that is not Buy or Strong Buy), so v7 and v8 are compared on
     * the same hours.
     */
    public const V7_RULE = 'v7_rule';

    /** Rules followed with the v7 exit on top of the stop, the targets and the time limit. */
    public const EXITS_ON_DOWNGRADE = [self::V7_RULE];

    public const PENDING = 'pending';

    /** Another one of the same rule was still open on this token, as a real signal would have been. */
    public const OVERLAP = 'overlap';

    public const NOT_ENTERED = 'not_entered';

    public const STOP_LOSS = 'stop_loss';

    public const TAKE_PROFIT = 'take_profit';

    public const EXPIRED = 'expired';

    /** Closed by the first analysis after entry that was no longer Buy or Strong Buy. */
    public const DOWNGRADED = 'downgraded';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => 'float',
            'entry_low' => 'float',
            'entry_high' => 'float',
            'stop_loss' => 'float',
            'take_profit_1' => 'float',
            'take_profit_2' => 'float',
            'take_profit_3' => 'float',
            'liquidity_usd' => 'float',
            'entry_price' => 'float',
            'exit_price' => 'float',
            'max_tp_hit' => 'integer',
            'net_r' => 'float',
            'cost_r' => 'float',
            'entered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Token, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    /**
     * @return BelongsTo<Analysis, $this>
     */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
