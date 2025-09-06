<?php

namespace App\Models\Location\Traits\Relationship;

use App\Models\Location\City;

trait DistrictRelationship
{
    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
