<?php

use App\Http\Controllers\Api\V1\Payment\PaymentGatewayController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'payment-gateways'], function () {
    Route::get('{order}/checkout', [PaymentGatewayController::class, 'checkout']);
});
