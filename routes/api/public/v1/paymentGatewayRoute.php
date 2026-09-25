<?php

use App\Http\Controllers\Api\V1\Payment\PaymentGatewayController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'payment-gateways'], function () {
    Route::get('{order}/providers', [PaymentGatewayController::class, 'providers'])->middleware('throttle:30,1');
    Route::post('{order}/mno-checkout', [PaymentGatewayController::class, 'checkout'])->middleware('throttle:3,1');
    Route::get('{order}/status', [PaymentGatewayController::class, 'status'])->middleware('throttle:30,1');
});
