<?php

namespace App\Services\Business;

use App\Models\Business\Business;

class BusinessProfileAccess
{
    public function permissions(Business $business): array
    {
        $user = auth()->user();
        $role = $user ? app(VendorBusinessAccess::class)->roleFor($user, $business) : null;
        $canManage = in_array($role, ['owner', 'vendor_manager', 'business_manager'], true);
        $registration = $role === 'owner'
            || ($role === 'vendor_manager' && $user?->vendors()
                ->whereKey($business->vendor_id)
                ->wherePivot('access_scope', 'all_businesses')
                ->exists());

        return [
            'can_view_profile' => $role !== null && $role !== 'vendor_viewer',
            'can_update_profile' => $canManage,
            'can_manage_operations' => $canManage,
            'can_manage_staff' => $canManage,
            'can_manage_registration' => $registration,
        ];
    }
}
