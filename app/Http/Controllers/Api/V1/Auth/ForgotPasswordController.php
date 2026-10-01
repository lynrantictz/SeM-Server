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
        return $this->sendResetInstructions($request);
    }

    protected function sendResetInstructions(Request $request, ?string $requiredType = null)
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $query = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true);

        $user = ($requiredType
            ? $query->where('type', $requiredType)
            : $query->whereIn('type', [UserType::OWNER->value, UserType::VENDOR->value]))->first();

        if ($user && $user->email_verified_at) {
            $token = Password::broker()->createToken($user);
            $baseUrl = $user->type === UserType::PAPERSTIC->value
                ? config('app.operations_url')
                : config('app.business_url');
            $resetUrl = rtrim($baseUrl, '/') . '/reset-password?'
                . http_build_query(['token' => $token, 'email' => $user->email]);

            $user->notify(new PasswordResetApi($resetUrl));
        }

        // Deliberately generic: this endpoint must not reveal which emails are registered.
        return $this->sendResponse([], 'If an active Paperstic email account matches, we have sent password reset instructions.');
    }
}
