<?php

use App\Http\Controllers\Api\V1\Operations\OperationsDashboardController;
use App\Http\Controllers\Api\V1\Operations\OperationsBusinessController;
use App\Http\Controllers\Api\V1\Operations\OperationsVendorController;
use Illuminate\Support\Facades\Route;

Route::prefix('operations')->group(function (): void {
    Route::get('dashboard', OperationsDashboardController::class)
        ->middleware('permission:operations.dashboard.view');

    Route::middleware('permission:operations.businesses.view')->group(function (): void {
        Route::get('vendors', [OperationsVendorController::class, 'index']);
        Route::get('vendors/{uuid}', [OperationsVendorController::class, 'show']);
        Route::get('businesses', [OperationsBusinessController::class, 'index']);
        Route::get('businesses/{uuid}', [OperationsBusinessController::class, 'show']);
        Route::get('businesses/{uuid}/team', [OperationsBusinessController::class, 'team']);
        Route::get('businesses/{uuid}/sections', [OperationsBusinessController::class, 'sections']);
        Route::get('businesses/{uuid}/orders', [OperationsBusinessController::class, 'orders']);
        Route::patch('businesses/{uuid}/status', [OperationsBusinessController::class, 'updateStatus'])
            ->middleware('permission:operations.businesses.manage');
        Route::patch('businesses/{uuid}/onboarding-payment', [OperationsBusinessController::class, 'updateOnboardingPayment'])
            ->middleware('permission:operations.businesses.manage');
        Route::post('businesses/{uuid}/onboarding-payment/proof', [OperationsBusinessController::class, 'uploadOnboardingProof'])
            ->middleware('permission:operations.businesses.manage');
        Route::get('businesses/{uuid}/onboarding-payment/proof', [OperationsBusinessController::class, 'downloadOnboardingProof']);
        Route::patch('businesses/{uuid}/payment-setting', [OperationsBusinessController::class, 'updatePaymentSetting'])
            ->middleware('permission:operations.settlements.manage');
        Route::post('businesses/{uuid}/payout-accounts', [OperationsBusinessController::class, 'storePayoutAccount'])
            ->middleware('permission:operations.settlements.manage');
        Route::put('businesses/{uuid}/payout-accounts/{accountUuid}', [OperationsBusinessController::class, 'updatePayoutAccount'])
            ->middleware('permission:operations.settlements.manage');
        Route::post('businesses/{uuid}/payout-accounts/{accountUuid}/review', [OperationsBusinessController::class, 'reviewPayoutAccount'])
            ->middleware('permission:operations.settlements.manage');
        Route::post('businesses/{uuid}/payout-accounts/{accountUuid}/default', [OperationsBusinessController::class, 'makeDefaultPayoutAccount'])
            ->middleware('permission:operations.settlements.manage');
        Route::get('businesses/{uuid}/payout-accounts/{accountUuid}/history', [OperationsBusinessController::class, 'payoutAccountHistory'])
            ->middleware('permission:operations.settlements.view');
        Route::post('businesses/{uuid}/payout-accounts/{accountUuid}/verification-document', [OperationsBusinessController::class, 'uploadPayoutVerificationDocument'])
            ->middleware('permission:operations.settlements.manage');
        Route::get('businesses/{uuid}/payout-accounts/{accountUuid}/verification-document', [OperationsBusinessController::class, 'downloadPayoutVerificationDocument'])
            ->middleware('permission:operations.settlements.view');
    });

    Route::middleware('permission:operations.kyc.view')->group(function (): void {
        Route::get('business-compliance-documents', [OperationsBusinessController::class, 'documents']);
        Route::get('business-compliance-documents/{uuid}/file', [OperationsBusinessController::class, 'download']);
    });

    Route::post('business-compliance-documents/{uuid}/review', [OperationsBusinessController::class, 'review'])
        ->middleware('permission:operations.kyc.review');
});
