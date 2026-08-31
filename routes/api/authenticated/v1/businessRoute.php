<?php

use App\Http\Controllers\Api\V1\Business\BusinessController;
use App\Http\Controllers\Api\V1\Business\BusinessTypeController;
use Illuminate\Support\Facades\Route;

Route::get('business-types', [BusinessTypeController::class, 'index']);

Route::group(['prefix' => 'businesses'], function () {
    Route::get('', [BusinessController::class, 'index']);
    Route::post('{vendor}', [BusinessController::class, 'store']);
    Route::put('{business}', [BusinessController::class, 'update']);
});
