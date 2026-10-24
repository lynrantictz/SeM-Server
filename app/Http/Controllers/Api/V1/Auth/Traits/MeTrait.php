<?php

namespace App\Http\Controllers\Api\V1\Auth\Traits;

trait MeTrait
{
    public function profile($request)
    {
        $user = $request->user();
        if ($user->type === 'agent') {
            $user->load('roles.permissions');
            $user->setRelation('vendors', $user->vendors()->with(['country', 'businesses.district.city.country', 'businesses.type'])->get());
            $user->setRelation('businesses', $user->businesses()->with(['vendor.country', 'district.city.country', 'type'])->get());
        } else {
            $user->load([
                'vendors.country',
                'vendors.businesses.district.city.country',
                'businesses.vendor.country',
                'businesses.district.city.country',
                'businesses.type',
                'roles',
                'roles.permissions',
            ]);
        }

        if ($user->type === 'agent') {
            $allowed = $user->businesses->where('pivot.is_active', true)->pluck('id');
            $vendors = $user->vendors->where('pivot.is_active', true)->values();
            foreach ($vendors as $vendor) {
                if ($vendor->pivot->access_scope !== 'all_businesses') {
                    $vendor->setRelation('businesses', $vendor->businesses->whereIn('id', $allowed)->values());
                }
            }
            $user->setRelation('vendors', $vendors);
        }
        $user->setRelation(
            'businesses',
            $user->businesses->filter(fn ($business) => (bool) $business->pivot->is_active)->values()
        );

        return $user;
    }
}
