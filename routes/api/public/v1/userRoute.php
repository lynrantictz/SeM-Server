<?php

use App\Http\Controllers\Api\V1\User\UserController;
use Illuminate\Support\Facades\Route;


Route::prefix('owners')->group(function () {
    Route::post('', [UserController::class, 'registerOwnerUser']);
    Route::get('check-email', [UserController::class, 'checkOwnerUserEmail']);
    Route::get('check-phone', [UserController::class, 'checkOwnerUserPhone']);
});
