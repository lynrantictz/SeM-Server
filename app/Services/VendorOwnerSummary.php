<?php

namespace App\Services;

use App\Models\Business\Vendor;
use App\Models\Business\VendorInvitation;

class VendorOwnerSummary
{
    public function forVendor(Vendor $vendor): array
    {
        $owner = $vendor->users()->wherePivot('is_primary', true)->first();
        $invitation = VendorInvitation::query()->where('vendor_id', $vendor->id)->where('assign_owner', true)
            ->when($owner, fn ($query) => $query->where('user_id', $owner->id))
            ->when(! $owner, fn ($query) => $query->whereNull('accepted_at'))
            ->with('user:id,uuid,name,email,is_active')->latest('id')->first();
        $person = $owner ?: $invitation?->user;
        $status = $owner
            ? ($owner->pivot->revoked_at ? 'revoked' : (! $owner->is_active || ! $owner->pivot->is_active ? 'disabled' : 'accepted'))
            : ($invitation ? ($invitation->expires_at->isPast() ? 'expired' : 'pending') : 'not_invited');

        return [
            'status' => $status,
            'name' => $person?->name,
            'email' => $person?->email,
            'account_active' => $person ? (bool) $person->is_active : null,
            'invited_at' => $invitation?->created_at?->toIso8601String() ?: $owner?->pivot->invited_at,
            'expires_at' => ! $owner ? $invitation?->expires_at?->toIso8601String() : null,
            'accepted_at' => $owner ? ($owner->pivot->accepted_at ?: $invitation?->accepted_at?->toIso8601String()) : null,
        ];
    }
}
