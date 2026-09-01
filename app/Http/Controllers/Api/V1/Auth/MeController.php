<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Api\V1\Auth\Traits\MeTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends BaseController
{
    use MeTrait;
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return $this->sendResponse([
            'user' => $this->profile($request),
        ], 'Welcome back!');
    }


//    public function businessUser($request)
//    {
//        $user = $request->user()->load(['businesses', 'businesses.vendor', 'roles', 'roles.permissions']);
//        $data = (object)[
//            'user' => [
//                'id'    => $user->id,
//                'name'  => $user->name,
//                'email' => $user->email,
//                'phone' => $user->phone,
//                'code'  => $user->code,
//                'title' => $user->businessUser->title,
//                'role' => $user->businessUser->business_role,
//                'uuid'  => $user->uuid,
//            ],
//            'business' => $user->businesses->map(fn($business) => [
//                'id'         => $business->id,
//                'name'       => $business->name,
//                'tin'       => $business->tin,
//                'is_active' => (bool) $business->pivot->is_primary,
//                'uuid'       => $business->uuid,
//                'vendor' => [
//                    'id'   => $business->vendor->id,
//                    'name' => $business->vendor->name,
//                    'uuid' => $business->vendor->uuid,
//                ],
//
//            ])->values(),
//            'roles' => $user->roles
//                ->pluck('name')
//                ->unique()
//                ->values(),
//
//            'permissions' => $user->roles
//                ->flatMap(fn($role) => $role->permissions->pluck('name'))
//                ->unique()
//                ->values(),
//        ];
//        return $this->sendResponse($data, 'Welcome back to ' . $user->businessUser->business->name . '!');
//    }

//$data = (object)[
//'user' => [
//'id'    => $user->id,
//'name'  => $user->name,
//'email' => $user->email,
//'phone' => $user->phone,
//'code'  => $user->code,
//'uuid'  => $user->uuid,
//'roles' => $user->roles
//->pluck('name')
//->unique()
//->values(),
//
//'permissions' => $user->roles
//->flatMap(fn($role) => $role->permissions->pluck('name'))
//->unique()
//->values(),
//],
//];
}
