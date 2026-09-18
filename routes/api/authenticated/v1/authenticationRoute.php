<?php

use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\NotificationController;
use App\Http\Controllers\Api\V1\Auth\OrderAlertPreferenceController;
use App\Http\Controllers\Api\V1\Business\VendorUserController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::post('logout', LogoutController::class);
Route::get('me', MeController::class);
Route::get('notifications', [NotificationController::class, 'index']);
Route::put('preferences/order-alerts', OrderAlertPreferenceController::class);
Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
Route::post('vendor-invitations/{invitation}/accept', [VendorUserController::class, 'acceptInApp']);
// Authenticated user password reset
Route::put('users/password-reset', PasswordResetController::class);
