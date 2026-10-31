<?php

namespace App\Services\Analysis;

use App\Enums\CandidateStatus;
use App\Enums\OrderStatus;
use App\Enums\Recommendation;
use App\Enums\WatchlistAction;
use App\Models\Analysis;
use App\Models\Token;
use App\Models\TokenCandidate;
use App\Models\WatchlistEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the Market list self-managing: unpinned tokens nobody is trading leave when their
 * score keeps falling, and strong discoveries take the place of the weakest ones when full.
 */
class WatchlistManager
{
    /**
     * Daily average final score for the decline window, oldest first.
     *
     * @return list<array{day: string, score: float}>
     */
    public function dailyScores(Token $token): array
    {
        return $this->dailyScoresFor([$token->id])->get($token->id, []);
    }

    /**
     * @param  list<int>  $tokenIds
     * @return Collection<int, list<array{day: string, score: float}>>
     */
    public function dailyScoresFor(array $tokenIds): Collection
    {
        return Analysis::query()
            ->whereIn('token_id', $tokenIds)
            ->where('created_at', '>=', now()->subDays(config('market.watchlist.decline_window_days'))->startOfDay())
            ->selectRaw('token_id, DATE(created_at) as day, AVG(final_score) as score')
            ->groupBy('token_id', DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get()
            ->groupBy('token_id')
            ->map(fn (Collection $rows): array => $rows->map(fn ($row): array => [
                'day' => (string) $row->day,
                'score' => round((float) $row->score, 1),
            ])->values()->all());
    }

    /**
     * Whether the daily scores fell most days and by enough points overall.
     *
     * @param  list<array{day: string, score: float}>  $days
     * @return array{declining: bool, change: float|null, down_days: int, days: int}
     */
    public static function trend(array $days): array
    {
        $config = config('market.watchlist');
        $count = count($days);

        if ($count < 2) {
            return ['declining' => false, 'change' => null, 'down_days' => 0, 'days' => $count];
        }

        $downDays = 0;

        for ($i = 1; $i < $count; $i++) {
            if ($days[$i]['score'] < $days[$i - 1]['score']) {
                $downDays++;
            }
        }

        $change = $days[$count - 1]['score'] - $days[0]['score'];

        return [
            'declining' => $count >= $config['min_history_days']
                && -$change >= $config['min_decline_points']
                && $downDays / ($count - 1) >= $config['min_down_day_ratio'],
            'change' => round($change, 1),
            'down_days' => $downDays,
            'days' => $count,
        ];
    }

    /**
     * A token with a position, a pending order or an open signal must stay on the list.
     */
    public function isInUse(Token $token): bool
    {
        return $token->positions()->where('quantity', '>', 0)->exists()
            || $token->orders()->where('status', OrderStatus::Pending)->exists()
            || $token->signals()->open()->exists();
    }

    /**
     * Why an unpinned token should leave the list now, or null to keep it.
     */
    public function removalReason(Token $token): ?string
    {
        if ($token->is_pinned || $this->isInUse($token)) {
            return null;
        }

        $latest = $token->latestAnalysis()->first();

        if (in_array($latest?->recommendation, [Recommendation::Avoid, Recommendation::StrongSell], true)) {
            return "Rekomendasi {$latest->recommendation->label()} (skor {$latest->final_score}): keluar segera.";
        }

        $trend = self::trend($this->dailyScores($token));

        if ($trend['declining'] && ($latest?->final_score ?? 0) < config('market.watchlist.keep_if_score_at_least')) {
            return sprintf(
                'Skor turun %.0f poin dalam %d hari (turun di %d dari %d hari), skor terakhir %d.',
                abs($trend['change']),
                $trend['days'],
                $trend['down_days'],
                $trend['days'] - 1,
                $latest?->final_score ?? 0,
            );
        }

        return null;
    }

    /**
     * Remove every token that qualifies for removal.
     *
     * @return list<string>
     */
    public function prune(): array
    {
        $removed = [];

        foreach (Token::query()->active()->where('is_pinned', false)->get() as $token) {
            if ($reason = $this->removalReason($token)) {
                $this->remove($token, $reason);
                $removed[] = $token->symbol;
            }
        }

        return $removed;
    }

    /**
     * The weakest unpinned, unused token that a better discovery may replace. A token that just came in
     * has no analysis yet, which would rank it as the weakest of all and throw it out again at once.
     */
    public function replaceable(): ?Token
    {
        return Token::query()
            ->active()
            ->where('is_pinned', false)
            ->whereDoesntHave('watchlistEvents', fn ($query) => $query
                ->where('action', WatchlistAction::Added)
                ->where('created_at', '>=', now()->subHours(config('market.watchlist.replace_grace_hours'))))
            ->with('latestAnalysis')
            ->get()
            ->filter(fn (Token $token): bool => ! $this->isInUse($token)
                && ($token->latestAnalysis?->final_score ?? 0) < config('market.watchlist.replace_below_score'))
            ->sortBy(fn (Token $token): int => $token->latestAnalysis?->final_score ?? 0)
            ->first();
    }

    public function hasFreeSlot(): bool
    {
        return Token::query()->active()->count() < config('market.simulator.max_tokens');
    }

    /**
     * Tokens removed recently should not bounce straight back in.
     */
    public function recentlyRemoved(int $tokenId): bool
    {
        return WatchlistEvent::query()
            ->where('token_id', $tokenId)
            ->where('action', WatchlistAction::Removed)
            ->where('created_at', '>=', now()->subDays(config('market.watchlist.re_add_cooldown_days')))
            ->exists();
    }

    public function remove(Token $token, string $reason): void
    {
        $token->update(['is_active' => false]);
        TokenCandidate::query()->where('token_id', $token->id)->update(['status' => CandidateStatus::Candidate]);
        $this->log($token, WatchlistAction::Removed, $reason, $token->latestAnalysis()->value('final_score'));
    }

    public function log(Token $token, WatchlistAction $action, string $reason, ?int $score = null): WatchlistEvent
    {
        return $token->watchlistEvents()->create(['action' => $action, 'reason' => mb_substr($reason, 0, 500), 'score' => $score]);
    }
}
