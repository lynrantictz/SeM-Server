<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Api\V1\Auth\UserVendorRegisterRequest;
use App\Models\Auth\EmailVerification;
use App\Repositories\Auth\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        Log::info('Email verification attempt', ['token' => $request->token, 'record' => $record]);

        if (! $record) {
            return response()->json([
                'verified' => false,
                'message' => 'Invalid token'
            ], 400);
        }

        if ($record->expires_at->isPast()) {
            return response()->json([
                'verified' => false,
                'message' => 'Token expired'
            ], 400);
        }

        $user = $record->user;

        $user->update([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // One-time use token, delete after verification
        $record->delete();

        // Optional: send welcome email or other post-verification actions
        $token = $user->createToken('api')->plainTextToken;

        return $this->sendResponse([
            'token' => $token,
        ], 'Email verified successfully.');
    }

    // public function registerUserVendor(UserVendorRegisterRequest $request)
    // {
    //     $data['user'] = $this->users->registerUserVendor($request->all());
    //     return $this->sendResponse($data, 'User registered successfully.', HTTP_CREATED);
    // }
}
