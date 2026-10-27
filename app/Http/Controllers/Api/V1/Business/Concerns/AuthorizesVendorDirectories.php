<?php

namespace App\Http\Controllers\Api\V1\Business\Concerns;

use Illuminate\Support\Facades\Auth;

trait AuthorizesVendorDirectories
{
    /**
     * The cross-vendor directories are for vendor owners and active managers.
     * Individual business access is intentionally handled by the show endpoints.
     */
    protected function ensureCanAccessVendorDirectories(): void
    {
        $user = Auth::user();
        $membershipTable = $user->vendorMembershipTable();
        $canAccess = $user->is_active && $user->vendors()
            ->where($membershipTable.'.is_active', true)
            ->where(function ($query) use ($membershipTable) {
                $query->where($membershipTable.'.is_primary', true)
                    ->orWhere($membershipTable.'.role', 'manager');
            })
            ->exists();

        abort_unless($canAccess, HTTP_FORBIDDEN, 'Only vendor owners and active vendor managers can access this directory.');
    }
}
