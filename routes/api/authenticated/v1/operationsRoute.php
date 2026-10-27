<?php

use App\Http\Controllers\Api\V1\Operations\OperationsBusinessController;
use App\Http\Controllers\Api\V1\Operations\OperationsDashboardController;
use App\Http\Controllers\Api\V1\Operations\OperationsVendorController;
use Illuminate\Support\Facades\Route;

Route::prefix('operations')->group(function (): void {
    Route::get('billing/demo', [\App\Http\Controllers\Api\V1\Operations\LocalBillingDemoController::class, 'index']);
    Route::post('billing/demo/run', [\App\Http\Controllers\Api\V1\Operations\LocalBillingDemoController::class, 'run']);
    Route::get('billing/restrictions', [\App\Http\Controllers\Api\V1\Operations\OperationsBillingController::class, 'restrictions']);
    Route::get('billing/export', [\App\Http\Controllers\Api\V1\Operations\OperationsBillingController::class, 'export']);
    Route::get('billing', [\App\Http\Controllers\Api\V1\Operations\OperationsBillingController::class, 'index']);
    Route::middleware('permission:operations.kyc.view')->group(function (): void {
        $controller = \App\Http\Controllers\Api\V1\Operations\OperationsReviewController::class;
        Route::get('reviews/summary', [$controller, 'summary']);
        Route::get('reviews', [$controller, 'index']);
        Route::get('reviews/{scope}/{uuid}', [$controller, 'show'])->whereIn('scope', ['vendor', 'business']);
        Route::post('reviews/{scope}/{uuid}/decision', [$controller, 'decide'])->whereIn('scope', ['vendor', 'business']);
        Route::get('review-documents/{uuid}/file', [$controller, 'file']);
        Route::post('review-documents/{uuid}/decision', [$controller, 'decideDocument']);
        Route::get('review-notifications', [$controller, 'notifications']);
        Route::post('review-notifications/read', [$controller, 'readNotifications']);
    });
    Route::get('dashboard', OperationsDashboardController::class)
        ->middleware('permission:operations.dashboard.view');

    Route::middleware('permission:operations.businesses.view')->group(function (): void {
        Route::get('businesses/{business}/billing/invoices/{invoice}', [\App\Http\Controllers\Api\V1\Business\BusinessBillingController::class, 'operationsInvoice']);
        Route::get('businesses/{business}/commission-policies', [\App\Http\Controllers\Api\V1\Business\BusinessCommissionController::class, 'operationsIndex']);
        Route::get('businesses/{business}/commission-policies/{policy}/fees', [\App\Http\Controllers\Api\V1\Business\BusinessCommissionController::class, 'operationsFees']);
        Route::post('businesses/{business}/commission-policies', [\App\Http\Controllers\Api\V1\Business\BusinessCommissionController::class, 'store']);
        Route::patch('businesses/{business}/commission-policies/{policy}', [\App\Http\Controllers\Api\V1\Business\BusinessCommissionController::class, 'update']);
        Route::post('businesses/{business}/commission-policies/{policy}/cancel', [\App\Http\Controllers\Api\V1\Business\BusinessCommissionController::class, 'cancel']);
        Route::get('businesses/{business}/billing/invoices/{invoice}/orders/export', [\App\Http\Controllers\Api\V1\Business\BusinessBillingController::class, 'operationsOrdersExport']);
        Route::get('businesses/{business}/billing/invoices/{invoice}/pdf', [\App\Http\Controllers\Api\V1\Business\BusinessBillingController::class, 'operationsPdf']);
        Route::get('businesses/{business}/billing', [\App\Http\Controllers\Api\V1\Business\BusinessBillingController::class, 'operationsShow']);
        Route::patch('businesses/{business}/billing', [\App\Http\Controllers\Api\V1\Business\BusinessBillingController::class, 'update']);
        Route::post('businesses/{business}/billing/invoices/{invoice}/receipts', [\App\Http\Controllers\Api\V1\Business\BusinessBillingController::class, 'receipt']);
        Route::patch('businesses/{business}/billing/invoices/{invoice}/deadline', [\App\Http\Controllers\Api\V1\Business\BusinessBillingController::class, 'extend']);
        Route::get('vendors', [OperationsVendorController::class, 'index']);
        Route::get('vendors/{uuid}', [OperationsVendorController::class, 'show']);
        Route::get('businesses', [OperationsBusinessController::class, 'index']);
        Route::get('businesses/{uuid}', [OperationsBusinessController::class, 'show']);
        Route::get('businesses/{uuid}/team', [OperationsBusinessController::class, 'team']);
        Route::get('businesses/{uuid}/sections', [OperationsBusinessController::class, 'sections']);
        Route::get('businesses/{uuid}/orders', [OperationsBusinessController::class, 'orders']);
        Route::patch('businesses/{uuid}/status', [OperationsBusinessController::class, 'updateStatus'])
            ->middleware('permission:operations.businesses.manage');
        Route::patch('businesses/{uuid}/tax-status', [OperationsBusinessController::class, 'updateTaxStatus'])
            ->middleware('permission:operations.kyc.approve');
        Route::patch('businesses/{uuid}/onboarding-payment', [OperationsBusinessController::class, 'updateOnboardingPayment'])
            ->middleware('permission:operations.businesses.manage');
        Route::post('businesses/{uuid}/onboarding-payment/proof', [OperationsBusinessController::class, 'uploadOnboardingProof'])
            ->middleware('permission:operations.businesses.manage');
        Route::get('businesses/{uuid}/onboarding-payment/proof', [OperationsBusinessController::class, 'downloadOnboardingProof']);
        Route::patch('businesses/{uuid}/payment-setting', [OperationsBusinessController::class, 'updatePaymentSetting'])
            ->middleware('permission:operations.settlements.manage');
        Route::post('businesses/{uuid}/payment-methods/{methodUuid}/review', [OperationsBusinessController::class, 'reviewPaymentMethod'])
            ->middleware('permission:operations.settlements.manage');
        Route::post('businesses/{uuid}/payment-methods/{methodUuid}/accounts/{accountUuid}/review', [OperationsBusinessController::class, 'reviewPaymentMethodAccount'])
            ->middleware('permission:operations.settlements.manage');
        Route::post('businesses/{uuid}/payment-methods/{methodUuid}/accounts/{accountUuid}/disable-review', [OperationsBusinessController::class, 'reviewPaymentMethodAccountDisable'])
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
        Route::get('businesses/{uuid}/payment-methods/{methodUuid}/accounts/{accountUuid}/history', [OperationsBusinessController::class, 'paymentMethodAccountHistory'])
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
