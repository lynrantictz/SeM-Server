<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Api\V1\Auth\UserVendorRegisterRequest;
use App\Models\Auth\EmailVerification;
use App\Notifications\WelcomeOwnerApi;
use App\Repositories\Auth\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends BaseController
{

    public function __construct(
        public UserRepository $users
    ) {}

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $record = EmailVerification::where(
            'token',
            hash('sha256', $request->token)
        )->first();

        Log::info('Email verification attempt', ['verification_found' => (bool) $record]);

        if (!$record) {
            return $this->sendError('Invalid token');
        }

        if ($record->expires_at->isPast()) {
            return $this->sendError('Token has expired');
//            return response()->json([
//                'verified' => false,
//                'message' => 'Token expired'
//            ], 400);
        }

        $user = $record->user;

        $user->update([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // One-time use token, delete after verification
        $record->delete();

        $user->notify(new WelcomeOwnerApi());

//        $token = $user->createToken('api')->plainTextToken;

        return $this->sendResponse([], 'Email verified successfully.');
    }

    public function resendVerification(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Please provide a valid email address.', $validator->errors());
        }

        $user = \App\Models\Auth\User::where('email', $request->string('email')->lower())->first();

        // Keep this response generic so the endpoint cannot be used to discover accounts.
        if (!$user || $user->email_verified_at || $user->type !== \App\Enums\User\UserType::OWNER->value) {
            return $this->sendResponse([], 'If the account exists and is not verified, a new verification email has been sent.');
        }

        $token = Str::random(64);
        EmailVerification::where('user_id', $user->id)->delete();
        EmailVerification::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(60),
        ]);

        $verificationUrl = rtrim(config('app.business_url'), '/') . '/email-verification?token=' . $token;
        $user->notify(new \App\Notifications\VerifyEmailApi($verificationUrl));

        return $this->sendResponse([], 'If the account exists and is not verified, a new verification email has been sent.');
    }

    // public function registerUserVendor(UserVendorRegisterRequest $request)
    // {
    //     $data['user'] = $this->users->registerUserVendor($request->all());
    //     return $this->sendResponse($data, 'User registered successfully.', HTTP_CREATED);
    // }
}
