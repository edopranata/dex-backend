<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

Route::get('/{path?}', SpaController::class)
    ->where('path', '^(?!api/|sanctum/|horizon(/|$)|up$|health$).*')
    ->name('spa');
