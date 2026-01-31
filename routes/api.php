<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    includeRouteFiles(__DIR__ . '/api/public/v1/');
    Route::middleware(['auth:api', 'business.context'])->group(function () {
        includeRouteFiles(__DIR__ . '/api/authenticated/v1/');
    });
});
