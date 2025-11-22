<?php

use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

//Route::get('/user', function (Request $request) {
//    return $request->user();
//})->middleware('auth:sanctum');

Route::post('/payments/azampay/callback', [PaymentWebhookController::class, 'handle']);

Route::prefix('v1')->group(function () {
    includeRouteFiles(__DIR__ . '/api/');
});
