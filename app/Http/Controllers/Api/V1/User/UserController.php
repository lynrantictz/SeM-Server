<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\RegisterVendorUser;
use App\Repositories\Auth\UserRepository;
use Illuminate\Http\Request;

class UserController extends BaseController
{
    public function __construct(
        public UserRepository $users
    ) {}


    public function registerVendorUser(RegisterVendorUser $request)
    {
        $this->users->registerVendorUser($request->all());
        return $this->sendResponse([], 'Your account have been registered successfully, please check your email to verify your account.', HTTP_CREATED);
    }
}
