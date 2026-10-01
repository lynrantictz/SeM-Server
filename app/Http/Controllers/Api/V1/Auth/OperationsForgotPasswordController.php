<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\User\UserType;
use Illuminate\Http\Request;

class OperationsForgotPasswordController extends ForgotPasswordController
{
    public function __invoke(Request $request)
    {
        return $this->sendResetInstructions($request, UserType::PAPERSTIC->value);
    }
}
