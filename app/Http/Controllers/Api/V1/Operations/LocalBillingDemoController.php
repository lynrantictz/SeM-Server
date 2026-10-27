<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Services\Business\LocalBillingDemo;
use Illuminate\Http\Request;

class LocalBillingDemoController extends BaseController
{
    private function access(Request $request, bool $write = false): void
    {
        $user = $request->user();
        abort_unless($user?->is_active && $user->type === 'paperstic' && $user->can('operations.businesses.view') && ($write ? $user->can('operations.settlements.manage') : ($user->can('operations.settlements.view') || $user->can('operations.settlements.manage'))), 403);
        app(LocalBillingDemo::class)->guard();
    }

    public function index(Request $request, LocalBillingDemo $demo)
    {
        $this->access($request);

        return $this->sendResponse($demo->status(), 'Local billing demo.');
    }

    public function run(Request $request, LocalBillingDemo $demo)
    {
        $this->access($request, true);

        return $this->sendResponse($demo->run(), 'Demo billing processed. Only the ten demo businesses were processed.');
    }
}
