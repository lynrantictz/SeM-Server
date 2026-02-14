<?php

namespace App\Models\Business\Trait\Relationship;


use App\Models\Business\Business;
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
}
