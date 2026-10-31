<?php

use App\Http\Controllers\Api\Admin\BackupController;
use App\Http\Controllers\Api\Admin\InfoController;
use App\Http\Controllers\Api\Admin\LogController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\SystemController;
use App\Http\Controllers\Api\Admin\TaskController;
use App\Http\Controllers\Api\Admin\TelegramController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContainerMetricController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfileTelegramController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WhaleController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::get('options', [AuthController::class, 'options'])->name('options');

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->name('two-factor');
    });

    Route::middleware('throttle:5,1')->group(function () {
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('password.reset');
        Route::post('two-factor/reset', [AuthController::class, 'resetTwoFactor'])->name('two-factor.reset');
    });

    // Its own counter (prefix): the login throttle above shares the per-IP key otherwise.
    Route::post('two-factor/reset-request', [AuthController::class, 'requestTwoFactorReset'])->middleware('throttle:3,10,two-factor-reset')->name('two-factor.reset-request');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

// Called by Telegram's servers (no session); authenticated by the webhook secret header.
Route::post('internal/container-metrics', [ContainerMetricController::class, 'store'])->middleware('throttle:60,1')->name('internal.container-metrics');

Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:120,1')->name('telegram.webhook');

Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:10,1')->name('profile.password');
    Route::put('profile/notifications', [ProfileController::class, 'notifications'])->name('profile.notifications');

    Route::post('profile/two-factor', [TwoFactorController::class, 'store'])->middleware('throttle:10,1')->name('two-factor.store');
    Route::post('profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::delete('profile/two-factor', [TwoFactorController::class, 'destroy'])->middleware('throttle:10,1')->name('two-factor.destroy');
    Route::post('profile/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:10,1')->name('two-factor.recovery-codes');

    Route::post('profile/telegram', [ProfileTelegramController::class, 'link'])->middleware('throttle:10,1')->name('profile.telegram.link');
    Route::delete('profile/telegram', [ProfileTelegramController::class, 'destroy'])->name('profile.telegram.destroy');
    Route::post('profile/telegram/test', [ProfileTelegramController::class, 'test'])->middleware('throttle:10,1')->name('profile.telegram.test');

    Route::get('feed', [FeedController::class, 'index'])->name('feed.index');

    Route::get('market/overview', [MarketController::class, 'overview'])->name('market.overview');

    Route::get('tokens/search', [TokenController::class, 'search'])->middleware('throttle:30,1')->name('tokens.search');
    Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
    Route::get('watchlist/events', [TokenController::class, 'events'])->name('watchlist.events');
    Route::patch('tokens/{token}/pin', [TokenController::class, 'pin'])->name('tokens.pin');
    Route::post('tokens', [TokenController::class, 'store'])->middleware('throttle:10,1')->name('tokens.store');
    Route::get('tokens/{token}', [TokenController::class, 'show'])->name('tokens.show');
    Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->name('tokens.destroy');
    Route::get('tokens/{token}/candles', [TokenController::class, 'candles'])->name('tokens.candles');
    Route::get('tokens/{token}/whales', [TokenController::class, 'whales'])->name('tokens.whales');
    Route::get('tokens/{token}/news', [TokenController::class, 'news'])->name('tokens.news');
    Route::get('tokens/{token}/analyses', [TokenController::class, 'analyses'])->name('tokens.analyses');

    Route::get('discovery', [DiscoveryController::class, 'index'])->name('discovery.index');
    Route::post('discovery/{candidate}/track', [DiscoveryController::class, 'track'])->middleware('throttle:10,1')->name('discovery.track');

    Route::get('whales', [WhaleController::class, 'index'])->name('whales.index');
    Route::get('news', [NewsController::class, 'index'])->name('news.index');

    Route::get('signals', [SignalController::class, 'index'])->name('signals.index');
    Route::get('signals/stats', [SignalController::class, 'stats'])->name('signals.stats');
    Route::post('signals/{signal}/follow', [SignalController::class, 'follow'])->name('signals.follow');

    Route::get('portfolio', [PortfolioController::class, 'show'])->name('portfolio.show');
    Route::get('portfolio/summary', [PortfolioController::class, 'summary'])->name('portfolio.summary');
    Route::post('portfolio/reset', [PortfolioController::class, 'reset'])->name('portfolio.reset');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
});

Route::middleware(['auth:sanctum', 'password.changed', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('info', [InfoController::class, 'index'])->name('info.index');
    Route::put('info/{service}/note', [InfoController::class, 'updateNote'])->name('info.note');

    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');
    Route::post('settings/test-ai', [SettingsController::class, 'testAi'])->middleware('throttle:10,1')->name('settings.test-ai');
    Route::post('settings/test-mail', [SettingsController::class, 'testMail'])->middleware('throttle:5,1')->name('settings.test-mail');

    Route::get('telegram', [TelegramController::class, 'show'])->name('telegram.show');
    Route::post('telegram/webhook', [TelegramController::class, 'connect'])->middleware('throttle:10,1')->name('telegram.connect');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/temporary-password', [UserController::class, 'temporaryPassword'])->middleware('throttle:10,1')->name('users.temporary-password');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups', [BackupController::class, 'store'])->middleware('throttle:5,1')->name('backups.store');
    Route::get('backups/{name}/download', [BackupController::class, 'download'])->name('backups.download');
    Route::delete('backups/{name}', [BackupController::class, 'destroy'])->name('backups.destroy');

    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('logs/{name}/download', [LogController::class, 'download'])->name('logs.download');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks/run', [TaskController::class, 'run'])->middleware('throttle:20,1')->name('tasks.run');

    Route::get('system', [SystemController::class, 'index'])->name('system.index');
    Route::get('system/containers', [SystemController::class, 'containers'])->name('system.containers');
    Route::post('system/failed-jobs/{uuid}/retry', [SystemController::class, 'retryFailed'])->name('system.failed.retry');
    Route::delete('system/failed-jobs/{uuid}', [SystemController::class, 'forgetFailed'])->name('system.failed.forget');
    Route::post('system/cache/clear', [SystemController::class, 'clearCache'])->name('system.cache.clear');
});
