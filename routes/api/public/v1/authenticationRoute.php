<?php

use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::post('login', LoginController::class);

// Locale debug endpoint (public): returns current locale and a sample translation
Route::get('locale', function () {
    return response()->json([
        'locale' => app()->getLocale(),
        'unauthenticated_message' => 'Unauthenticated',
    ]);
});

Route::post('/verify-email', [AuthController::class, 'verifyEmail']);

Route::post('/payments/azampay/callback', [PaymentWebhookController::class, 'handle']);
