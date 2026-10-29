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

Schedule::command('market:candles --timeframe=15m --timeframe=1h')->everyTenMinutes()->withoutOverlapping();
Schedule::command('market:candles --timeframe=4h --timeframe=1d')->hourlyAt(5)->withoutOverlapping();

Schedule::command('market:whales')->everyTenMinutes()->withoutOverlapping();

Schedule::command('market:news')->hourlyAt(10)->withoutOverlapping();

Schedule::command('market:security')->everySixHours(15)->withoutOverlapping();

Schedule::command('market:discover')->cron('25,55 * * * *')->withoutOverlapping();

Schedule::command('market:watchlist')->hourlyAt(40)->withoutOverlapping();

Schedule::command('market:analyze')->cron('3,18,33,48 * * * *')->withoutOverlapping();

Schedule::command('model:prune')->dailyAt('03:00');
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
