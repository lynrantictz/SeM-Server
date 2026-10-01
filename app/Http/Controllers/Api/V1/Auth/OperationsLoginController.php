<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\User\UserType;
use Illuminate\Http\Request;

class OperationsLoginController extends LoginController
{
    public function __invoke(Request $request)
    {
        return $this->authenticateForType($request, UserType::PAPERSTIC->value);
    }
}
