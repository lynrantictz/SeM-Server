<?php

namespace App\Models\Business\Trait\Relationship;


use App\Models\Business\Business;
use App\Models\Auth\User;
use App\Models\Location\Country;

trait VendorRelationship
{
    public function businesses()
    {
        return $this->hasMany(Business::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'vendor_user', 'vendor_id', 'user_id')
            ->withPivot(['is_primary', 'role', 'access_scope', 'is_active', 'invited_at', 'accepted_at', 'revoked_at'])
            ->withTimestamps();
    }
}
