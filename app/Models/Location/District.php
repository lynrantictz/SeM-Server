<?php

namespace App\Models\Location;

use App\Models\BaseModel;
use App\Models\Location\Traits\Attribute\DistrictAttribute;
use App\Models\Location\Traits\Relationship\DistrictRelationship;

class District extends BaseModel
{
    use DistrictAttribute, DistrictRelationship;
}
