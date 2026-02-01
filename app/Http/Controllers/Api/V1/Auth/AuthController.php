<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Api\V1\Auth\UserVendorRegisterRequest;
use App\Models\Auth\EmailVerification;
use App\Repositories\Auth\UserRepository;
use Illuminate\Http\Request;

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
        ]);

        // One-time use
        $record->delete();

        return response()->json([
            'verified' => true
        ]);
    }

    public function registerUserVendor(UserVendorRegisterRequest $request)
    {
        $data['user'] = $this->users->registerUserVendor($request->all());
        return $this->sendResponse($data, 'User registered successfully.', HTTP_CREATED);
    }
}
