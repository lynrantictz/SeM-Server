<?php

namespace App\Services\Business;

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessUser;

/** Resolves a user's effective access to one business. */
class VendorBusinessAccess
{
    public function roleFor(User $user, Business $business): ?string
    {
        if (! $user->is_active) {
            return null;
        }

        if ($user->type === 'business') {
            $membership = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            return $membership?->business_role;
        }

        $membership = $user->vendors()->whereKey($business->vendor_id)->first();
        if (! $membership || ! $membership->pivot->is_active) {
            return null;
        }

        if ($membership->pivot->is_primary) {
            return 'owner';
        }

        $role = $membership->pivot->role;
        if (! in_array($role, ['manager', 'viewer'], true)) {
            return null;
        }

        if ($membership->pivot->access_scope !== 'all_businesses'
            && ! $user->businesses()->whereKey($business->id)->wherePivot('is_active', true)->exists()) {
            return null;
        }

        return $role === 'manager' ? 'vendor_manager' : 'vendor_viewer';
    }
}
