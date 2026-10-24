<?php

namespace App\Models\Auth\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Business\BusinessUser;
use App\Models\Business\Vendor;

trait UserRelationship
{
    public function businesses()
    {
        return $this->belongsToMany(Business::class, $this->type === 'agent' ? 'agent_business_assignments' : 'business_user')
            ->withPivot([
                'title',
                'business_role',
                'business_staff_role_id',
                'is_active',
                'activated_at',
                'deactivated_at',
            ])
            ->withTimestamps();
    }

    public function businessUser()
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function vendorMembershipTable(): string
    {
        return $this->type === 'agent' ? 'agent_vendor_assignments' : 'vendor_user';
    }

    public function vendors()
    {
        return $this->belongsToMany(Vendor::class, $this->vendorMembershipTable(), 'user_id', 'vendor_id')
            ->withPivot(['is_primary', 'role', 'access_scope', 'is_active', 'invited_at', 'accepted_at', 'revoked_at'])
            ->withTimestamps();
    }
}
