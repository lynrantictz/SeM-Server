<?php

namespace App\Models\Auth\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Business\BusinessUser;

trait UserRelationship
{
    public function businesses()
    {
        return $this->belongsToMany(Business::class);
    }

    public function businessUser()
    {
        return $this->hasOne(BusinessUser::class);
    }
}
