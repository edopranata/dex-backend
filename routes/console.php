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
// Tokens that left the Market keep their 15m data, in turns at a minute the queue is usually empty.
Schedule::command('market:candles --out-of-market')->hourlyAt(20)->withoutOverlapping();

Schedule::command('market:whales')->cron('0,15,30,45 * * * *')->withoutOverlapping();

Schedule::command('market:news')->hourlyAt(10)->withoutOverlapping();

Schedule::command('market:security')->everySixHours(15)->withoutOverlapping();
// Holder data of tokens that left the Market, once a day away from the Market run and discovery (:25, :55).
Schedule::command('market:security --out-of-market')->dailyAt('03:35')->withoutOverlapping();

Schedule::command('market:discover')->cron('25,55 * * * *')->withoutOverlapping();

// v6 comparison: shadow signals followed on stored 15m candles; no API requests.
Schedule::command('market:shadow-signals')->hourlyAt(50)->withoutOverlapping();

// Fase R, recording only: the day's lists of most profitable traders, after the UTC day has closed. Six short
// requests to Birdeye, none to GeckoTerminal; skipped while no key is set.
Schedule::command('market:trader-rankings')->dailyAt('00:35')->withoutOverlapping();

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
