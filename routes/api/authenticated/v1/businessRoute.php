<?php

use App\Http\Controllers\Api\V1\Business\BusinessController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'businesses'], function () {
    Route::get('', [BusinessController::class, 'index']);
    Route::post('{vendor}', [BusinessController::class, 'store']);
    Route::put('{business}', [BusinessController::class, 'update']);
});
