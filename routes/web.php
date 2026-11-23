<?php

use App\Http\Controllers\CentralAuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

// Login through the central account at tune-in.trade (only when Admin → Pengaturan allows it).
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/auth/redirect', [CentralAuthController::class, 'redirect'])->name('central-auth.redirect');
    Route::get('/auth/callback', [CentralAuthController::class, 'callback'])->name('central-auth.callback');
});

Route::get('/{path?}', SpaController::class)
    ->where('path', '^(?!api/|sanctum/|auth/(redirect|callback)$|horizon(/|$)|up$|health$).*')
    ->name('spa');
