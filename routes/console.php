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

// GeckoTerminal allows ~10 requests/minute: one 15-minute cycle (17 tokens x 2 candle + 1 whale request)
// takes about 5 minutes, and analysis starts right after it with fresh candles.
Schedule::command('market:candles --timeframe=15m --timeframe=1h')->cron('0,15,30,45 * * * *')->withoutOverlapping();
Schedule::command('market:candles --timeframe=4h --timeframe=1d')->hourlyAt(8)->withoutOverlapping();

Schedule::command('market:whales')->cron('0,15,30,45 * * * *')->withoutOverlapping();

Schedule::command('market:news')->hourlyAt(10)->withoutOverlapping();

Schedule::command('market:security')->everySixHours(15)->withoutOverlapping();

Schedule::command('market:discover')->cron('25,55 * * * *')->withoutOverlapping();

Schedule::command('market:watchlist')->hourlyAt(40)->withoutOverlapping();

Schedule::command('market:analyze')->cron('6,21,36,51 * * * *')->withoutOverlapping();

Schedule::command('app:telegram-daily-summary')->hourlyAt(0);

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('model:prune')->dailyAt('03:00');
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
