<?php

namespace App\Http\Controllers\Api\V1\Auth\Traits;

use Illuminate\Http\JsonResponse;

trait MeTrait
{
    public function profile($request)
    {
        $user = $request->user()->load([
            'vendors.country',
            'vendors.businesses.district.city.country',
            'businesses.vendor.country',
            'businesses.district.city.country',
            'businesses.type',
            'roles',
            'roles.permissions',
        ]);

        $user->setRelation(
            'businesses',
            $user->businesses->filter(fn ($business) => (bool) $business->pivot->is_active)->values()
        );

        return $user;
    }
}
