<?php

use App\Http\Controllers\Api\V1\Business\BusinessController;
use App\Http\Controllers\Api\V1\Business\BusinessStaffController;
use App\Http\Controllers\Api\V1\Business\BusinessStaffRoleController;
use App\Http\Controllers\Api\V1\Business\BusinessTypeController;
use Illuminate\Support\Facades\Route;

Route::get('business-types', [BusinessTypeController::class, 'index']);
Route::get('business-staff-roles', [BusinessStaffRoleController::class, 'index']);

Route::group(['prefix' => 'businesses'], function () {
    Route::get('{business}/management-access', [BusinessStaffController::class, 'managementAccess']);
    Route::get('{business}/staff', [BusinessStaffController::class, 'index']);
    Route::post('{business}/staff', [BusinessStaffController::class, 'store']);
    Route::post('{business}/staff/{user}/reset-password', [BusinessStaffController::class, 'resetPassword']);
    Route::put('{business}/staff/{user}', [BusinessStaffController::class, 'update']);
    Route::get('', [BusinessController::class, 'index']);
    Route::get('{business}', [BusinessController::class, 'show']);
    Route::post('{vendor}', [BusinessController::class, 'store']);
    Route::put('{business}', [BusinessController::class, 'update']);
});
