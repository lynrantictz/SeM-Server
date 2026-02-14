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
        $request->validate([
            'email'    => ['nullable', 'email'],
            'code'    => ['nullable', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (!$request->email && !$request->code) {
            return $this->sendError(
                'Either email or code is required.',
                ['login' => ['Either email or code is required.']]
            );
        }

        $user = User::when(
            $request->email,
            fn($q) => $q->where('email', $request->email)
        )->when(
            $request->code,
            fn($q) => $q->where('code', $request->code)
        )->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->sendError(
                'Invalid credentials.',
                ['login' => ['Invalid credentials.']]
            );
        }

        if (!$user->email_verified_at && $user->type === UserType::VENDOR->value) {
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

        // Optional: revoke old tokens
        $user->tokens()->delete();

        $token = $user->createToken('api')->plainTextToken;

        return $this->sendResponse([
            'token' => $token,
        ], 'Login successful.');
    }
}
