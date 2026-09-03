<?php

use App\Http\Controllers\Api\V1\Business\VendorController;
use App\Http\Controllers\Api\V1\Business\VendorUserController;
use App\Http\Controllers\Api\V1\Business\ComplianceDocumentController;
use Illuminate\Support\Facades\Route;


Route::group(['prefix' => 'vendors'], function () {
    Route::get('{vendor}/documents', [ComplianceDocumentController::class, 'vendorIndex']);
    Route::post('{vendor}/documents', [ComplianceDocumentController::class, 'vendorStore']);
    Route::get('{vendor}/documents/{document}/download', [ComplianceDocumentController::class, 'vendorDownload']);
    Route::post('{vendor}/documents/{document}/update', [ComplianceDocumentController::class, 'vendorUpdate']);
    Route::delete('{vendor}/documents/{document}', [ComplianceDocumentController::class, 'vendorDestroy']);
    Route::get('', [VendorController::class, 'index']);
    Route::get('{vendor}/businesses', [VendorController::class, 'businesses']);
    Route::get('{vendor}/users', [VendorUserController::class, 'index']);
    Route::post('{vendor}/users', [VendorUserController::class, 'store']);
    Route::put('{vendor}/users/{user}', [VendorUserController::class, 'update']);
    Route::post('{vendor}/users/{user}/resend-invitation', [VendorUserController::class, 'resendInvitation']);
    Route::get('{vendor}', [VendorController::class, 'show']);
    Route::post('', [VendorController::class, 'store']);
    Route::put('{vendor}', [VendorController::class, 'update']);
});
