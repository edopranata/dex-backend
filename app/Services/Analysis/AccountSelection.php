<?php

namespace App\Services\Analysis;

use App\Models\EquitySnapshot;
use App\Models\User;
use App\Services\Trading\PortfolioSummary;
use Illuminate\Support\Collection;

/**
 * Judges the auto-follow accounts against the selection limits in config market.selection (owner, 28 Nov 2026): enough
 * closed lots of the current strategy version, a win rate and an average R per lot after fees, gas and price impact,
 * and an equity drawdown that counts the lots still open.
 *
 * A lot's R is the one AccountReport gives (realized P&L over the risk at entry, to the signal's stop). A lot wins above
 * +0.05R and is even within ±0.05R; one without R wins above zero. The win rate is wins over all closed lots, even ones
 * included. The same figures over every version are shown next to them for reference.
 */
class AccountSelection
{
    public const ACTIVE = 'active';

    public const INSUFFICIENT = 'insufficient';

    public const FAILED = 'failed';

    private const EVEN_R = 0.05;

    public function __construct(private AccountReport $report, private PortfolioSummary $portfolio) {}

    /**
     * @return array{limits: array{min_trades: int, min_win_rate: float, min_avg_r: float, max_drawdown_pct: float}, version: string, accounts: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $limits = self::limits();
        $version = StrategyVersion::current();
        $rows = [];

        foreach (User::AUTO_FOLLOW_ACCOUNTS as $key => $definition) {
            $account = User::query()->where('email', $definition['email'])->first();

            if ($account !== null) {
                $rows[] = $this->judge($key, $definition['label'], $account, $version, $limits);
            }
        }

        return [
            'limits' => $limits,
            'version' => $version,
            'accounts' => collect($rows)->sortBy(fn (array $row): string => match ($row['status']) {
                self::ACTIVE => '0', self::FAILED => '1', default => '2',
            }.str_pad((string) (100000 - $row['closed']), 6, '0', STR_PAD_LEFT))->values()->all(),
        ];
    }

    /**
     * @return array{min_trades: int, min_win_rate: float, min_avg_r: float, max_drawdown_pct: float}
     */
    public static function limits(): array
    {
        return [
            'min_trades' => (int) config('market.selection.min_trades'),
            'min_win_rate' => (float) config('market.selection.min_win_rate'),
            'min_avg_r' => (float) config('market.selection.min_avg_r'),
            'max_drawdown_pct' => (float) config('market.selection.max_drawdown_pct'),
        ];
    }

    /**
     * @param  array{min_trades: int, min_win_rate: float, min_avg_r: float, max_drawdown_pct: float}  $limits
     * @return array<string, mixed>
     */
    private function judge(string $key, string $label, User $account, string $version, array $limits): array
    {
        $lots = $this->report->lots($account, null);
        $current = $this->tally($lots->filter(fn (array $lot): bool => $lot['signal']['version'] === $version));
        $drawdown = $this->drawdownPct($account);
        $closed = $current['closed'];
        $reasons = [];

        if ($closed < $limits['min_trades']) {
            $status = self::INSUFFICIENT;
            $reasons[] = "Baru {$closed} dari {$limits['min_trades']} lot selesai di versi ini";
        } else {
            if ($current['win_rate'] < $limits['min_win_rate']) {
                $reasons[] = "Win rate {$current['win_rate']}% di bawah {$limits['min_win_rate']}%";
            }

            if ($current['avg_r'] === null) {
                $reasons[] = 'R per lot tidak bisa dihitung';
            } elseif ($current['avg_r'] < $limits['min_avg_r']) {
                $reasons[] = sprintf('Rata-rata %+.2fR di bawah %+.2fR', $current['avg_r'], $limits['min_avg_r']);
            }

            if ($drawdown >= $limits['max_drawdown_pct']) {
                $reasons[] = "Drawdown {$drawdown}% tidak di bawah {$limits['max_drawdown_pct']}%";
            }

            $status = $reasons === [] ? self::ACTIVE : self::FAILED;
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'reasons' => $reasons,
            ...$current,
            'open' => $lots->where('status', 'open')->count(),
            'drawdown_pct' => $drawdown,
            'all_versions' => array_intersect_key($this->tally($lots), array_flip(['closed', 'win_rate', 'avg_r'])),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lots
     * @return array{closed: int, wins: int, even: int, losses: int, win_rate: float|null, avg_r: float|null, r_trades: int}
     */
    private function tally(Collection $lots): array
    {
        $wins = $even = $losses = 0;
        $rs = [];

        foreach ($lots->where('status', 'closed') as $lot) {
            if ($lot['r'] === null) {
                $lot['pnl_usd'] > 0 ? $wins++ : $losses++;

                continue;
            }

            $rs[] = (float) $lot['r'];
            match (true) {
                $lot['r'] > self::EVEN_R => $wins++,
                $lot['r'] >= -self::EVEN_R => $even++,
                default => $losses++,
            };
        }

        $closed = $wins + $even + $losses;

        return [
            'closed' => $closed,
            'wins' => $wins,
            'even' => $even,
            'losses' => $losses,
            'win_rate' => $closed > 0 ? round($wins / $closed * 100, 1) : null,
            'avg_r' => $rs === [] ? null : round(array_sum($rs) / count($rs), 2),
            'r_trades' => count($rs),
        ];
    }

    /**
     * Largest fall from a peak of the equity snapshots, counting the start and the equity now (open lots at the price).
     */
    private function drawdownPct(User $account): float
    {
        $portfolio = $this->portfolio->for($account);
        $snapshots = EquitySnapshot::query()->where('user_id', $account->id)->orderBy('sampled_at')->get(['equity_usd'])
            ->push(new EquitySnapshot(['equity_usd' => $portfolio['equity_usd']]));

        return PerformanceReport::maxDrawdownPct($snapshots, (float) $portfolio['starting_balance']) ?? 0.0;
    }
}
