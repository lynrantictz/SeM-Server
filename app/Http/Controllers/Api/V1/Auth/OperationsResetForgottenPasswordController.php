<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\User\UserType;
use Illuminate\Http\Request;

class OperationsResetForgottenPasswordController extends ResetForgottenPasswordController
{
    public function __invoke(Request $request)
    {
        return $this->resetPassword($request, UserType::PAPERSTIC->value);
    }
}
