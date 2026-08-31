<?php

use App\Http\Controllers\Api\V1\Business\VendorController;
use App\Http\Controllers\Api\V1\Business\VendorUserController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'vendors'], function () {
    Route::get('', [VendorController::class, 'index']);
    Route::get('{vendor}/businesses', [VendorController::class, 'businesses']);
    Route::get('{vendor}/users', [VendorUserController::class, 'index']);
    Route::post('{vendor}/users', [VendorUserController::class, 'store']);
    Route::put('{vendor}/users/{user}', [VendorUserController::class, 'update']);
    Route::post('{vendor}/users/{user}/resend-invitation', [VendorUserController::class, 'resendInvitation']);
    Route::get('{vendor}', [VendorController::class, 'show']);
    Route::post('', [VendorController::class, 'store']);
    Route::put('{vendor}', [VendorController::class, 'update']);
});
