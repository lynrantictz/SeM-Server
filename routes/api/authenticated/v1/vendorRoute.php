<?php

use App\Http\Controllers\Api\V1\Business\VendorController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'vendors'], function () {
    Route::get('', [VendorController::class, 'index']);
    Route::get('{vendor}', [VendorController::class, 'show']);
    Route::post('', [VendorController::class, 'store']);
    Route::put('{vendor}', [VendorController::class, 'update']);
});
