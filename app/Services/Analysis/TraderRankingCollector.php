<?php

namespace App\Services\Analysis;

use App\Models\TraderRanking;
use App\Services\MarketData\BirdeyeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Stores each provider's lists of most profitable traders once a day (Fase R, recording only). Nothing reads these
 * rows yet: the first question is whether a wallet that is on a list one week is still on it the next.
 */
class TraderRankingCollector
{
    public const SOURCE = 'birdeye';

    public function __construct(private BirdeyeClient $birdeye) {}

    /**
     * @return array{skipped: string|null, lists: int, wallets: int, failed: int}
     */
    public function run(): array
    {
        $config = config('market.radar');
        $result = ['skipped' => null, 'lists' => 0, 'wallets' => 0, 'failed' => 0];

        if (! $config['enabled'] || ! $this->birdeye->hasKey()) {
            return [...$result, 'skipped' => $config['enabled'] ? 'no_key' : 'disabled'];
        }

        $today = now()->toDateString();

        foreach ($config['chains'] as $chain) {
            foreach ($config['lists'] as $list) {
                if (! $this->spend($config['monthly_request_budget'])) {
                    return [...$result, 'skipped' => 'budget'];
                }

                try {
                    $traders = $this->birdeye->topTraders($chain, $list['period'], $list['sort_by'], $config['limit']);
                } catch (Throwable $e) {
                    Log::warning("Radar: Birdeye {$chain} {$list['period']} {$list['sort_by']} failed: {$e->getMessage()}");
                    $result['failed']++;

                    continue;
                } finally {
                    // The free package allows one request per second.
                    Sleep::for(1100)->milliseconds();
                }

                $rows = [];

                foreach (array_values($traders) as $index => $trader) {
                    if (! isset($trader['address'])) {
                        continue;
                    }

                    $rows[] = [
                        'source' => self::SOURCE,
                        'chain_id' => $chain,
                        'wallet' => $trader['address'],
                        'period' => $list['period'],
                        'sort_by' => $list['sort_by'],
                        'list_rank' => $index + 1,
                        'pnl_usd' => self::money($trader['pnl'] ?? null),
                        'realized_pnl_usd' => self::money($trader['realized_pnl'] ?? null),
                        'unrealized_pnl_usd' => self::money($trader['unrealized_pnl'] ?? null),
                        'volume_usd' => self::money($trader['volume'] ?? null),
                        'trade_count' => isset($trader['trade_count']) ? (int) $trader['trade_count'] : null,
                        'score' => isset($trader['score']) ? round((float) $trader['score'], 2) : null,
                        'seen_on' => $today,
                        'created_at' => now(),
                    ];
                }

                // A second run on the same day replaces that day's list instead of doubling it.
                TraderRanking::query()->upsert($rows, ['source', 'chain_id', 'period', 'sort_by', 'seen_on', 'wallet']);
                $result['lists']++;
                $result['wallets'] += count($rows);
            }
        }

        return $result;
    }

    /**
     * Count one request against this month's budget; false once the budget is used up.
     */
    private function spend(int $budget): bool
    {
        $key = 'radar:birdeye:requests:'.now()->format('Y-m');

        if ((int) Cache::get($key, 0) >= $budget) {
            return false;
        }

        Cache::add($key, 0, now()->addDays(40));
        Cache::increment($key);

        return true;
    }

    /**
     * Amounts beyond the column (22 digits before the point) are broken data.
     */
    private static function money(mixed $value): ?float
    {
        return is_numeric($value) && abs((float) $value) < 1e21 ? round((float) $value, 2) : null;
    }
}
