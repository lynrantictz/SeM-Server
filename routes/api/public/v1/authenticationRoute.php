<?php

use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\ResetForgottenPasswordController;
use App\Http\Controllers\Api\V1\Business\VendorUserController;
use Illuminate\Support\Facades\Route;

Route::post('login', LoginController::class);
Route::post('password/forgot', ForgotPasswordController::class)->middleware('throttle:5,1');
Route::post('password/reset', ResetForgottenPasswordController::class)->middleware('throttle:5,1');
Route::get('vendor-invitations/preview', [VendorUserController::class, 'showInvitation']);
Route::post('vendor-invitations/accept', [VendorUserController::class, 'acceptInvitation']);

// Locale debug endpoint (public): returns current locale and a sample translation
Route::get('locale', function () {
    return response()->json([
        'locale' => app()->getLocale(),
        'unauthenticated_message' => 'Unauthenticated',
    ]);
});

Route::get('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-verification', [AuthController::class, 'resendVerification'])
    ->middleware('throttle:5,1');

Route::post('/payments/azampay/callback', [PaymentWebhookController::class, 'handle']);
