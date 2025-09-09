<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Repositories\Auth\UserRepository;

class LoginController extends BaseController
{
    protected UserRepository $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

}
