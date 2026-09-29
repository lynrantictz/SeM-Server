<?php

use App\Http\Controllers\Api\V1\Payment\PaymentGatewayController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'payment-gateways'], function () {
    Route::get('links/{token}', [PaymentGatewayController::class, 'paymentLink'])->middleware('throttle:30,1');
    Route::post('{order}/share-link', [PaymentGatewayController::class, 'createSharePaymentLink'])->middleware('throttle:5,1');
    Route::get('{order}/providers', [PaymentGatewayController::class, 'providers'])->middleware('throttle:30,1');
    Route::post('{order}/mno-checkout', [PaymentGatewayController::class, 'checkout'])->middleware('throttle:3,1');
    // Demo reviewers may retry after a simulated gateway timeout. The endpoint
    // remains session-protected and is unavailable outside sandbox mode.
    Route::post('{order}/complete-sandbox-demo', [PaymentGatewayController::class, 'completeSandboxDemo'])->middleware('throttle:20,1');
    Route::get('{order}/status', [PaymentGatewayController::class, 'status'])->middleware('throttle:30,1');
});
