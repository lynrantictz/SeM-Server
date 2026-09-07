<?php

use App\Http\Controllers\Api\V1\Business\BusinessController;
use App\Http\Controllers\Api\V1\Business\BusinessStaffController;
use App\Http\Controllers\Api\V1\Business\BusinessStaffRoleController;
use App\Http\Controllers\Api\V1\Business\BusinessTypeController;
use App\Http\Controllers\Api\V1\Business\BusinessMenuSettingsController;
use App\Http\Controllers\Api\V1\Business\ComplianceDocumentController;
use App\Http\Controllers\Api\V1\Section\ServiceAreaController;
use Illuminate\Support\Facades\Route;

Route::get('business-types', [BusinessTypeController::class, 'index']);
Route::get('business-staff-roles', [BusinessStaffRoleController::class, 'index']);

Route::group(['prefix' => 'businesses'], function () {
    Route::get('{business}/menu-settings', [BusinessMenuSettingsController::class, 'show']);
    Route::put('{business}/menu-settings', [BusinessMenuSettingsController::class, 'update']);
    Route::get('{business}/service-areas', [ServiceAreaController::class, 'index']);
    Route::get('{business}/service-areas/sections', [ServiceAreaController::class, 'sections']);
    Route::get('{business}/service-areas/subsections', [ServiceAreaController::class, 'subSections']);
    Route::get('{business}/service-points', [ServiceAreaController::class, 'servicePoints']);
    Route::post('{business}/sections', [ServiceAreaController::class, 'storeSection']);
    Route::put('{business}/sections/{section}', [ServiceAreaController::class, 'updateSection']);
    Route::post('{business}/sections/{section}/subsections', [ServiceAreaController::class, 'storeSubSection']);
    Route::put('{business}/subsections/{subSection}', [ServiceAreaController::class, 'updateSubSection']);
    Route::post('{business}/service-points', [ServiceAreaController::class, 'storeServicePoint']);
    Route::put('{business}/service-points/{servicePoint}', [ServiceAreaController::class, 'updateServicePoint']);
    Route::post('{business}/service-points/{servicePoint}/rotate-code', [ServiceAreaController::class, 'rotateCode']);
    Route::get('{business}/logo', [BusinessController::class, 'logo']);
    Route::post('{business}/logo', [BusinessController::class, 'updateLogo']);
    Route::get('{business}/documents', [ComplianceDocumentController::class, 'businessIndex']);
    Route::post('{business}/documents', [ComplianceDocumentController::class, 'businessStore']);
    Route::get('{business}/documents/{document}/download', [ComplianceDocumentController::class, 'businessDownload']);
    Route::post('{business}/documents/{document}/update', [ComplianceDocumentController::class, 'businessUpdate']);
    Route::delete('{business}/documents/{document}', [ComplianceDocumentController::class, 'businessDestroy']);
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
