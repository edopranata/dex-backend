<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an auto-follow account did with a signal it follows: bought it, or skipped it and why.
 */
#[Fillable(['user_id', 'account', 'signal_id', 'decision', 'reason', 'message', 'source', 'decided_at'])]
class AutoFollowDecision extends Model
{
    public const BOUGHT = 'bought';

    public const SKIPPED = 'skipped';

    /** Reason codes for a skip. */
    public const POSITION_LIMIT = 'position_limit';

    public const DAILY_LOSS = 'daily_loss';

    public const BELOW_MINIMUM = 'below_minimum';

    public const REJECTED = 'rejected';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    public static function record(User $account, string $key, Signal $signal, string $decision, ?string $reason = null, ?string $message = null): void
    {
        static::query()->updateOrCreate(
            ['user_id' => $account->id, 'signal_id' => $signal->id],
            ['account' => $key, 'decision' => $decision, 'reason' => $reason, 'message' => $message === null ? null : mb_substr($message, 0, 255), 'source' => 'live', 'decided_at' => now()],
        );
    }
}
