<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends BaseController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        try {
            $user = $request->user();

            // Revoke all personal access tokens if model supports it
            if ($user && method_exists($user, 'tokens')) {
                try {
                    $user->tokens()->delete();
                } catch (\Throwable $e) {
                    // ignore token deletion errors
                }
            }

            // Logout web session if present
            try {
                Auth::logout();
                $request->session()?->invalidate();
                $request->session()?->regenerateToken();
            } catch (\Throwable $e) {
                // ignore session logout errors
            }

            return $this->sendResponse([], __('auth.logged_out'));
        } catch (\Throwable $e) {
            return $this->sendError(__('auth.logout_failed'), ['error' => $e->getMessage()], 500);
        }
    }
}
