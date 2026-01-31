<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordResetController extends BaseController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $request->validate([
            'current_password'      => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ]);

        $user = $request->user();
        if (!$user) {
            return $this->sendError(__('auth.unauthenticated'), [], 401);
        }

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return $this->sendError(__('auth.invalid_current_password_title'), ['current_password' => [__('auth.invalid_current_password_detail')]]);
        }

        DB::transaction(function () use ($user, $request) {
            $user->password = $request->input('password');
            $user->save();
            try {
                $user->tokens()->delete();
            } catch (\Throwable $e) {
                // Ignore if model doesn't use tokens
            }
        });

        // Revoke existing tokens to require fresh login across devices


        return $this->sendResponse(['user' => $user], 'password updated successfully.');
    }
}
