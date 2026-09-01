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
        $canAccess = Auth::user()
            ->vendors()
            ->where(function ($query) {
                $query->where('vendor_user.is_primary', true)
                    ->orWhere(function ($membershipQuery) {
                        $membershipQuery
                            ->where('vendor_user.is_active', true)
                            ->where('vendor_user.role', 'manager');
                    });
            })
            ->exists();

        abort_unless($canAccess, HTTP_FORBIDDEN, 'Only vendor owners and active vendor managers can access this directory.');
    }
}
