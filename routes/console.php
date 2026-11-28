<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Market data & analysis pipeline
|--------------------------------------------------------------------------
|
| Cron cukup satu baris: * * * * * php artisan schedule:run
| Tugas berat (GeckoTerminal, GoPlus, AI) dijalankan lewat queue worker.
|
*/

Schedule::command('market:prices')->everyFifteenSeconds()->withoutOverlapping(1);

Schedule::command('market:snapshots')->hourly();

// GeckoTerminal allows ~5 requests/minute from this server. One request per token fetches 15m; 1h, 4h and
// 1d are built from it (8 Nov), so a cycle is about 20 candle + 20 whale requests instead of 60, plus the
// separate 4h and 1d runs that are no longer needed.
Schedule::command('market:candles')->cron('0,15,30,45 * * * *')->withoutOverlapping();
// Tokens that left the Market keep their 15m data, in four small turns an hour (16 Nov 2026): each lands in the gap
// after a Market cycle (about 43 GeckoTerminal requests, done by about 8.5 minutes past the quarter) instead of one
// turn at :20 that piled onto the :15 and :30 cycles. Keeps :27-:29 and :57-:59 free for deploys.
Schedule::command('market:candles --out-of-market')->cron('9,24,39,54 * * * *')->withoutOverlapping();

Schedule::command('market:whales')->cron('0,15,30,45 * * * *')->withoutOverlapping();

Schedule::command('market:news')->hourlyAt(10)->withoutOverlapping();

Schedule::command('market:security')->everySixHours(15)->withoutOverlapping();
// Holder data of tokens that left the Market, once a day away from the Market run and discovery (:12, :42).
Schedule::command('market:security --out-of-market')->dailyAt('03:35')->withoutOverlapping();

// In the gap after the :00 and :30 cycles and the turn above (16 Nov 2026; was :25 and :55, where it waited
// behind the queue up to 8 minutes).
Schedule::command('market:discover')->cron('12,42 * * * *')->withoutOverlapping();

// v6 comparison: shadow signals followed on stored 15m candles; no API requests.
Schedule::command('market:shadow-signals')->hourlyAt(50)->withoutOverlapping();

// FutureSim's long strategies as DEX paper accounts (28 Nov 2026): after each candle cycle, from stored candles only;
// an hour's decisions wait for each token's candles of that hour, so the later runs pick up tokens that came in late.
Schedule::command('strategies:run')->cron('8,23,38 * * * *')->withoutOverlapping(15);
Schedule::command('strategies:run --snapshot')->hourlyAt(53)->withoutOverlapping(15);

// Fase R: `market:trader-rankings` (Birdeye's most profitable traders) is no longer scheduled. The 12-26 Nov 2026
// test failed (dex/review/birdeye/hasil-26nov.md) and the owner stopped the daily recording on 26 Nov; the command
// stays runnable from Admin, and the stored lists are kept.

// Market-turn triggers of docs/UJI_ULANG_PASAR_NAIK.md (workspace repo): two public requests (Bybit daily BTC,
// alternative.me Fear & Greed) after the UTC day has closed; Telegram to the admins only when a trigger is newly met.
Schedule::command('app:market-turn-check')->dailyAt('01:05')->withoutOverlapping();

// Token Baru (26 Nov 2026, recording only): every tracked young pool sampled on DEX Screener each minute (one request
// per 30 pools) plus one GoPlus check; GeckoTerminal new_pools twice an hour, one request per network on the
// market-data queue, after the :15 and :45 cycles.
Schedule::command('newpairs:track')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('newpairs:discover')->cron('20,50 * * * *')->withoutOverlapping();

Schedule::command('market:watchlist')->hourlyAt(40)->withoutOverlapping();

Schedule::command('market:analyze')->cron('11,26,41,56 * * * *')->withoutOverlapping();

// What the price did 1h/4h/24h after every analysis (performance statistics).
Schedule::command('market:evaluate')->cron('7,22,37,52 * * * *')->withoutOverlapping();

Schedule::command('app:telegram-daily-summary')->hourlyAt(0);

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Healthchecks.io: a missing ping means the server or scheduler is down; /fail means a service is unhealthy.
Schedule::command('app:heartbeat')->everyFiveMinutes();

// Equity of the auto-follow account: the curve and drawdown in Laporan.
Schedule::command('market:equity-snapshot')->everyFifteenMinutes();

Schedule::command('model:prune')->dailyAt('03:00');
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
