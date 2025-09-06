<?php

namespace App\Models\Location\Traits\Relationship;

use App\Models\Location\Country;
use App\Models\Location\District;

trait CityRelationship
{
    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function districts()
    {
        return $this->hasMany(District::class);
    }
}
