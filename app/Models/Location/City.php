<?php

namespace App\Models\Location;

use App\Models\BaseModel;
use App\Models\Location\Traits\Attribute\CityAttribute;
use App\Models\Location\Traits\Relationship\CityRelationship;

class City extends BaseModel
{
    use CityAttribute, CityRelationship;

    protected $hidden = [
        'created_at',
        'updated_at',
        'uuid',
    ];
}
