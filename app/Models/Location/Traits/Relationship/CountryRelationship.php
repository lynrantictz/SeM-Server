<?php

namespace App\Models\Location\Traits\Relationship;

use App\Models\Business\Vendor;
use App\Models\Location\City;
use App\Models\Location\District;

trait CountryRelationship
{
    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function districts()
    {
        return $this->hasManyThrough(District::class, City::class);
    }

    public function vendors()
    {
        return $this->hasMany(Vendor::class);
    }
}
