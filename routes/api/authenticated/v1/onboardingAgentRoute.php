<?php

use App\Http\Controllers\Api\V1\Business\AgentOnboardingController;
use App\Http\Controllers\Api\V1\Operations\OnboardingAgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('operations/onboarding-agents')->group(function () {
    Route::get('', [OnboardingAgentController::class, 'index']);
    Route::get('options', [OnboardingAgentController::class, 'options']);
    Route::post('', [OnboardingAgentController::class, 'store']);
    Route::get('{uuid}', [OnboardingAgentController::class, 'show']);
    Route::put('{uuid}', [OnboardingAgentController::class, 'update']);
    Route::patch('{uuid}/status', [OnboardingAgentController::class, 'status']);
    Route::post('{uuid}/invite', [OnboardingAgentController::class, 'invite']);
    Route::post('{uuid}/assignments', [OnboardingAgentController::class, 'assign']);
    Route::patch('{uuid}/assignments/{vendorUuid}/progress', [OnboardingAgentController::class, 'progress']);
    Route::delete('{uuid}/assignments/{vendorUuid}', [OnboardingAgentController::class, 'unassign']);
});
Route::prefix('onboarding-agent')->group(function () {
    Route::get('', [AgentOnboardingController::class, 'index']);
    Route::patch('vendors/{uuid}/progress', [AgentOnboardingController::class, 'progress']);
    Route::post('vendors/{uuid}/owner', [AgentOnboardingController::class, 'owner']);
});

Route::get('operations/businesses/{uuid}/onboarding-agents',[OnboardingAgentController::class, 'businessAgents']);
