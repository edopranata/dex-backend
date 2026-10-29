<?php

use App\Http\Controllers\Api\Admin\BackupController;
use App\Http\Controllers\Api\Admin\LogController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\SystemController;
use App\Http\Controllers\Api\Admin\TaskController;
use App\Http\Controllers\Api\Admin\TelegramController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\WhaleController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::get('options', [AuthController::class, 'options'])->name('options');

    Route::middleware('throttle:10,1')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

Route::middleware('auth:sanctum')->group(function () {
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
    Route::post('portfolio/reset', [PortfolioController::class, 'reset'])->name('portfolio.reset');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
});

Route::middleware(['auth:sanctum', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');
    Route::post('settings/test-ai', [SettingsController::class, 'testAi'])->middleware('throttle:10,1')->name('settings.test-ai');

    Route::post('telegram/chats', [TelegramController::class, 'chats'])->middleware('throttle:10,1')->name('telegram.chats');
    Route::post('telegram/test', [TelegramController::class, 'test'])->middleware('throttle:10,1')->name('telegram.test');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
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
    Route::post('system/failed-jobs/{uuid}/retry', [SystemController::class, 'retryFailed'])->name('system.failed.retry');
    Route::delete('system/failed-jobs/{uuid}', [SystemController::class, 'forgetFailed'])->name('system.failed.forget');
    Route::post('system/cache/clear', [SystemController::class, 'clearCache'])->name('system.cache.clear');
});
