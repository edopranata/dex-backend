<?php

namespace App\Services\Analysis;

use App\Models\EquitySnapshot;
use App\Models\Signal;
use App\Models\StrategyAccount;
use App\Models\StrategyPosition;
use App\Models\StrategySnapshot;
use App\Models\User;
use App\Services\Strategies\StrategyBroker;
use App\Services\Trading\PortfolioSummary;
use Illuminate\Support\Carbon;
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

    public function __construct(private AccountReport $report, private PortfolioSummary $portfolio, private StrategyBroker $broker) {}

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

        $versionStart = Signal::query()->where('strategy_version', $version)->min('created_at');
        $strategies = StrategyAccount::query()->where('is_active', true)->where('kind', '!=', 'benchmark')->orderBy('id')->get()
            ->map(fn (StrategyAccount $account): array => $this->judgeStrategy($account, $limits));

        return [
            'limits' => $limits,
            'version' => $version,
            // The current version's first signal: the auto-follow accounts are judged from then on.
            'version_started_at' => $versionStart === null ? null : Carbon::parse($versionStart)->toIso8601String(),
            'accounts' => self::sorted($rows),
            'strategy_accounts' => self::sorted($strategies->all()),
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
                $reasons[] = 'Win rate '.self::decimal($current['win_rate']).'% di bawah '.self::decimal($limits['min_win_rate']).'%';
            }

            if ($current['avg_r'] === null) {
                $reasons[] = 'R per lot tidak bisa dihitung';
            } elseif ($current['avg_r'] < $limits['min_avg_r']) {
                $reasons[] = 'Rata-rata '.self::signedR($current['avg_r']).' di bawah '.self::signedR($limits['min_avg_r']);
            }

            if ($drawdown >= $limits['max_drawdown_pct']) {
                $reasons[] = 'Drawdown '.self::decimal($drawdown).'% tidak di bawah '.self::decimal($limits['max_drawdown_pct']).'%';
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
            'account_started_at' => $account->created_at?->toIso8601String(),
        ];
    }

    /**
     * A strategy account from FutureSim (28 Nov 2026) on its closed positions, as FutureSim judges its own: R is the net
     * result over the risk at entry; a position without a stop has no R and counts as won above zero.
     *
     * @param  array{min_trades: int, min_win_rate: float, min_avg_r: float, max_drawdown_pct: float}  $limits
     * @return array<string, mixed>
     */
    private function judgeStrategy(StrategyAccount $account, array $limits): array
    {
        $positions = StrategyPosition::query()->where('strategy_account_id', $account->id)->get(['status', 'state', 'net_pnl', 'cost_basis']);
        $closed = $positions->where('status', StrategyPosition::CLOSED);
        $lots = $closed->map(function (StrategyPosition $position): array {
            $risk = (float) ($position->state['initial_risk'] ?? 0);

            return ['status' => 'closed', 'r' => $risk > 0 ? round($position->net_pnl / $risk, 2) : null, 'pnl_usd' => $position->net_pnl];
        });
        $tally = $this->tally($lots->values());
        $pcts = $closed->filter(fn (StrategyPosition $p): bool => $p->cost_basis > 0)->map(fn (StrategyPosition $p): float => $p->net_pnl / $p->cost_basis * 100);
        $drawdown = $this->strategyDrawdownPct($account);
        [$status, $reasons] = $this->verdict($tally, $drawdown, $limits, 'trade selesai', partial: $tally['r_trades'] < $tally['closed']);

        return [
            'key' => $account->key,
            'label' => $account->label,
            'kind' => $account->kind,
            'status' => $status,
            'reasons' => $reasons,
            ...$tally,
            'open' => $positions->where('status', StrategyPosition::OPEN)->count(),
            'pending' => $positions->where('status', StrategyPosition::PENDING)->count(),
            'avg_pct' => $pcts->isEmpty() ? null : round((float) $pcts->avg(), 2),
            'drawdown_pct' => $drawdown,
            'started_at' => $account->started_at->toIso8601String(),
        ];
    }

    /**
     * Status and reasons from a tally and the drawdown.
     *
     * @param  array{closed: int, wins: int, even: int, losses: int, win_rate: float|null, avg_r: float|null, r_trades: int}  $tally
     * @param  array{min_trades: int, min_win_rate: float, min_avg_r: float, max_drawdown_pct: float}  $limits
     * @return array{0: string, 1: list<string>}
     */
    private function verdict(array $tally, float $drawdown, array $limits, string $unit, bool $partial = false): array
    {
        $closed = $tally['closed'];
        if ($closed < $limits['min_trades']) {
            return [self::INSUFFICIENT, ["Baru {$closed} dari {$limits['min_trades']} {$unit}"]];
        }

        $reasons = [];
        if ($tally['win_rate'] < $limits['min_win_rate']) {
            $reasons[] = 'Win rate '.self::decimal($tally['win_rate']).'% di bawah '.self::decimal($limits['min_win_rate']).'%';
        }
        if ($tally['avg_r'] === null) {
            $reasons[] = 'Tanpa stop, R per trade tidak bisa dihitung';
        } elseif ($partial) {
            $reasons[] = 'Sebagian trade tanpa stop, R per trade belum lengkap';
        } elseif ($tally['avg_r'] < $limits['min_avg_r']) {
            $reasons[] = 'Rata-rata '.self::signedR($tally['avg_r']).' di bawah '.self::signedR($limits['min_avg_r']);
        }
        if ($drawdown >= $limits['max_drawdown_pct']) {
            $reasons[] = 'Drawdown '.self::decimal($drawdown).'% tidak di bawah '.self::decimal($limits['max_drawdown_pct']).'%';
        }

        return [$reasons === [] ? self::ACTIVE : self::FAILED, $reasons];
    }

    /**
     * Active first, then failed, then waiting for data; more closed trades first within each.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows): array
    {
        return collect($rows)->sortBy(fn (array $row): string => match ($row['status']) {
            self::ACTIVE => '0', self::FAILED => '1', default => '2',
        }.str_pad((string) (100000 - $row['closed']), 6, '0', STR_PAD_LEFT))->values()->all();
    }

    private function strategyDrawdownPct(StrategyAccount $account): float
    {
        $snapshots = StrategySnapshot::query()->where('strategy_account_id', $account->id)->orderBy('taken_at')->pluck('equity')
            ->push($this->broker->equity($account))
            ->map(fn (mixed $equity): EquitySnapshot => new EquitySnapshot(['equity_usd' => (float) $equity]));

        return PerformanceReport::maxDrawdownPct($snapshots, $account->starting_balance) ?? 0.0;
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

    /**
     * A number the Indonesian way for the reasons: decimal comma, no trailing zeros (48,9; 80; 15,54).
     */
    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    private static function signedR(float $value): string
    {
        return ($value >= 0 ? '+' : '−').number_format(abs($value), 2, ',', '.').'R';
    }
}
