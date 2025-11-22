<?php

use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

//Route::get('/user', function (Request $request) {
//    return $request->user();
//})->middleware('auth:sanctum');

Route::prefix('v1')->group(function () {
    Route::post('/azam-pay/webhook', [PaymentWebhookController::class, 'handle']);

    includeRouteFiles(__DIR__ . '/api/');
});
