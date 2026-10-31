<?php

namespace App\Services\Analysis;

/**
 * Identifies the strategy a signal came from: the version in config (raised when the logic changes)
 * plus a short hash of the parameters, so tuning weights in the admin panel also counts as a new version.
 */
class StrategyVersion
{
    public static function current(): string
    {
        $config = config('market.analysis');
        $parameters = array_intersect_key($config, array_flip([
            'timeframe', 'trend_timeframe', 'weights', 'thresholds', 'min_onchain_score', 'tp_r_multiples', 'min_stop_pct',
            'sl_atr_min', 'sl_atr_max', 'signal_entry_expiry_hours', 'signal_max_hours',
        ]));
        ksort($parameters);

        return $config['strategy_version'].'-'.substr(md5((string) json_encode($parameters)), 0, 6);
    }
}
