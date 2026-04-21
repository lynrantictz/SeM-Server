<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\RegisterVendorUser;
use App\Models\Location\Country;
use App\Repositories\Auth\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends BaseController
{
    public function __construct(
        public UserRepository $users
    ) {}


    /**
     * Register Owner
     * @param RegisterVendorUser $request
     * @return JsonResponse
     */
    public function registerOwnerUser(RegisterVendorUser $request): JsonResponse
    {
        $this->users->registerOwnerUser($request->all());
        return $this->sendResponse([], 'Your account have been registered successfully, please check your email to verify your account.', HTTP_CREATED);
    }

    function checkOwnerUserEmail(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $data['available'] = $this->users->checkOwnerUserEmail($email);
        return $this->sendResponse($data, '');
    }
    function checkOwnerUserPhone(Request $request): JsonResponse
    {
        $code = $request->input('countryCode');
        $phone = $request->input('phone');
        $formattedPhone = setPhoneFormat($code, $phone);
        $data['available'] = $this->users->checkOwnerUserPhone($formattedPhone);
        return $this->sendResponse($data, '');
    }
}
