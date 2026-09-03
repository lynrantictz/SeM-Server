<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\User\UserType;
use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ResetForgottenPasswordController extends BaseController
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $eligible = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('type', [UserType::OWNER->value, UserType::VENDOR->value])
            ->where('is_active', true)
            ->exists();

        if (!$eligible) {
            return $this->sendError('This password reset link is invalid or has expired.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $status = Password::broker()->reset([
            'email' => $email,
            'token' => $validated['token'],
            'password' => $validated['password'],
            'password_confirmation' => $validated['password_confirmation'],
        ], function (User $user, string $password) {
            $user->forceFill([
                // UserAttribute hashes every assigned password. Supplying an
                // already-hashed value here would hash it a second time.
                'password' => $password,
                'must_change_password' => false,
            ])->save();

            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return $this->sendError('This password reset link is invalid or has expired.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->sendResponse([], 'Your password has been reset. You can now sign in.');
    }
}
