<?php

namespace App\Services\Business;

use App\Models\Business\Business;

class BusinessProfileAccess
{
    public function permissions(Business $business): array
    {
        $user = auth()->user();
        $manage = false;
        $registration = false;
        if ($user?->is_active) {
            if ($user->type === 'business') {
                $manage = $user->businesses()->whereKey($business->id)->wherePivot('is_active', true)
                    ->wherePivot('business_role', 'business_manager')->exists();
            } else {
                $membership = $user->vendors()->whereKey($business->vendor_id)->first();
                $manage = (bool) ($membership && ($membership->pivot->is_primary
                    || ($membership->pivot->is_active && $membership->pivot->role === 'manager'
                        && ($membership->pivot->access_scope === 'all_businesses'
                            || $user->businesses()->whereKey($business->id)->wherePivot('is_active', true)->exists()))));
                $registration = $manage;
            }
        }

        return [
            'can_update_profile' => $manage,
            'can_manage_operations' => $manage,
            'can_manage_staff' => $manage,
            'can_manage_registration' => $registration,
        ];
    }
}
