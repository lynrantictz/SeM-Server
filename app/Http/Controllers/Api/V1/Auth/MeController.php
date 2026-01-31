<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeController extends BaseController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $user = $request->user()->load(['businesses', 'businesses.vendor', 'roles', 'roles.permissions']);
        $data = (object)[
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'code'  => $user->code,
                'title' => $user->businessUser->title,
                'role' => $user->businessUser->business_role,
                // 'type'  => $user->user_type,
            ],
            'business' => $user->businesses->map(fn($business) => [
                'id'         => $business->id,
                'name'       => $business->name,
                'tin'       => $business->tin,
                'is_active' => (bool) $business->pivot->is_primary,
                'vendor' => [
                    'id'   => $business->vendor->id,
                    'name' => $business->vendor->name,
                ],

            ])->values(),
            'roles' => $user->roles
                ->pluck('name')
                ->unique()
                ->values(),

            'permissions' => $user->roles
                ->flatMap(fn($role) => $role->permissions->pluck('name'))
                ->unique()
                ->values(),
        ];
        return $this->sendResponse($data, 'authenticated user retrieved successfully.');
    }
}
