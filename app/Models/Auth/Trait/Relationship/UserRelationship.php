<?php

namespace App\Models\Auth\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Business\BusinessUser;
use App\Models\Business\Vendor;

trait UserRelationship
{
    public function businesses()
    {
        return $this->belongsToMany(Business::class);
    }

    public function businessUser()
    {
        return $this->hasMany(BusinessUser::class);
    }

    public function vendors()
    {
        return $this->belongsToMany(Vendor::class, 'vendor_user', 'user_id', 'vendor_id')
            ->withPivot(['is_primary', 'role', 'access_scope', 'is_active', 'invited_at', 'accepted_at', 'revoked_at'])
            ->withTimestamps();
    }
}
