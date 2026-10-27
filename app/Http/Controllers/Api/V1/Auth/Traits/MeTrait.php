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

        $allowedBusinessIds = $user->businesses->where('pivot.is_active', true)->pluck('id');
        $vendors = $user->vendors->filter(fn ($vendor) => (bool) $vendor->pivot->is_active)->values();
        foreach ($vendors as $vendor) {
            if (! $vendor->pivot->is_primary && $vendor->pivot->access_scope !== 'all_businesses') {
                $vendor->setRelation('businesses', $vendor->businesses->whereIn('id', $allowedBusinessIds)->values());
            }
        }
        $user->setRelation('vendors', $vendors);
        $businesses = $user->type === 'business'
            ? $user->businesses->filter(fn ($business) => (bool) $business->pivot->is_active)->values()
            : $user->vendors->flatMap(fn ($vendor) => $vendor->businesses);
        $userBusinesses = $user->businesses
            ->filter(fn ($business) => (bool) $business->pivot->is_active);
        if ($user->type !== 'business') {
            $userBusinesses = $userBusinesses->whereIn('id', $businesses->pluck('id'));
        }
        $user->setRelation('businesses', $userBusinesses->values());
        $verifiedIds = app(\App\Services\Discovery\DiscoverableBusinesses::class)->verifiedQuery()
            ->whereIn('businesses.id', $businesses->pluck('id')->unique())->pluck('businesses.id')->flip();
        foreach ($businesses as $business) {
            $business->setAttribute('is_verified', $verifiedIds->has($business->id));
        }

        return $user;
    }
}
