<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\User\UserType;
use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends BaseController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'login' => ['nullable', 'string', 'max:255'],
            // Retained temporarily for existing clients during the transition.
            'email' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim((string) ($validated['login'] ?? $validated['email'] ?? $validated['code'] ?? ''));
        if ($identifier === '') {
            return $this->sendError(
                'Either email or code is required.',
                ['login' => ['Either email or code is required.']]
            );
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($identifier)])
            ->orWhereRaw('UPPER(code) = ?', [mb_strtoupper($identifier)])
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->sendError(
                'Invalid credentials.',
                ['login' => ['Invalid credentials.']]
            );
        }

        if (!$user->email_verified_at && in_array($user->type, [UserType::OWNER->value, UserType::VENDOR->value], true)) {
            return $this->sendError(
                'Email Not verified.',
                ['account' => ['Your email has not been verified.']]
            );
        }

        if (!$user->is_active) {
            return $this->sendError(
                'Account disabled.',
                ['account' => ['Your account has been disabled.']]
            );
        }

        if ($user->type === UserType::BUSINESS->value) {
            $hasActiveBusinessAccess = $user->businessUser()
                ->where('is_active', true)
                ->whereHas('business', fn ($query) => $query->where('is_active', true))
                ->exists();

            if (!$hasActiveBusinessAccess) {
                return $this->sendError(
                    'Staff access is disabled.',
                    ['account' => ['Your staff access is disabled or the assigned business is inactive. Contact your business manager.']]
                );
            }
        }

        // Optional: revoke old tokens
        $user->tokens()->delete();

        $token = $user->createToken('api')->plainTextToken;

        return $this->sendResponse([
            'token' => $token,
            'must_change_password' => $user->must_change_password,
        ], 'Login successful.');
    }
}
