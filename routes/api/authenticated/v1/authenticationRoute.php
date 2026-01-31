<?php

use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::post('logout', LogoutController::class);
Route::get('me', MeController::class);
// Authenticated user password reset
Route::put('users/password-reset', PasswordResetController::class);
