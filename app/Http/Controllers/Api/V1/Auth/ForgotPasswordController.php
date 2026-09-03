<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\User\UserType;
use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Notifications\PasswordResetApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends BaseController
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereIn('type', [UserType::OWNER->value, UserType::VENDOR->value])
            ->where('is_active', true)
            ->first();

        if ($user && $user->email_verified_at) {
            $token = Password::broker()->createToken($user);
            $resetUrl = rtrim(config('app.business_url'), '/') . '/reset-password?'
                . http_build_query(['token' => $token, 'email' => $user->email]);

            $user->notify(new PasswordResetApi($resetUrl));
        }

        // Deliberately generic: this endpoint must not reveal which emails are registered.
        return $this->sendResponse([], 'If an active Paperstick email account matches, we have sent password reset instructions.');
    }
}
