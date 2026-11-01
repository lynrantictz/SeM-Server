<?php

use App\Http\Controllers\Api\V1\Payment\PaymentGatewayController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'payment-gateways'], function () {
    Route::get('links/{token}', [PaymentGatewayController::class, 'paymentLink'])->middleware('throttle:30,1');
    Route::get('{order}/share-link', [PaymentGatewayController::class, 'sharePaymentLinkActivity'])->middleware('throttle:30,1');
    // The order itself enforces the two-share limit. Keep a higher transport
    // throttle so previous validation attempts from the same network do not
    // block a legitimate share request.
    Route::post('{order}/share-link', [PaymentGatewayController::class, 'createSharePaymentLink'])->middleware('throttle:20,1');
});
