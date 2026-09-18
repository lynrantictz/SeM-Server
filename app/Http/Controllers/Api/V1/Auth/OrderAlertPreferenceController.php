<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderAlertPreferenceController extends BaseController
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $validated) {
            $request->user()->update([
                'order_alerts_enabled' => (bool) $validated['enabled'],
            ]);
        });

        return $this->sendResponse([
            'order_alerts_enabled' => (bool) $validated['enabled'],
        ], 'Order alert preference updated successfully.');
    }
}
