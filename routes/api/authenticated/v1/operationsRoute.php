<?php

use App\Http\Controllers\Api\V1\Operations\OperationsDashboardController;
use App\Http\Controllers\Api\V1\Operations\OperationsBusinessController;
use Illuminate\Support\Facades\Route;

Route::prefix('operations')->group(function (): void {
    Route::get('dashboard', OperationsDashboardController::class)
        ->middleware('permission:operations.dashboard.view');

    Route::middleware('permission:operations.businesses.view')->group(function (): void {
        Route::get('businesses', [OperationsBusinessController::class, 'index']);
        Route::get('businesses/{uuid}', [OperationsBusinessController::class, 'show']);
    });

    Route::middleware('permission:operations.kyc.view')->group(function (): void {
        Route::get('business-compliance-documents', [OperationsBusinessController::class, 'documents']);
        Route::get('business-compliance-documents/{uuid}/file', [OperationsBusinessController::class, 'download']);
    });

    Route::post('business-compliance-documents/{uuid}/review', [OperationsBusinessController::class, 'review'])
        ->middleware('permission:operations.kyc.review');
});
