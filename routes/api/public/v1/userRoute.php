<?php

use App\Http\Controllers\Api\V1\User\UserController;
use Illuminate\Support\Facades\Route;

Route::post('users/vendors/register', [UserController::class, 'registerVendorUser']);
