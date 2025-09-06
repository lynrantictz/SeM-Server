<?php

namespace App\Models\Location;

use App\Models\BaseModel;
use App\Models\Location\Traits\Attribute\CountryAttribute;
use App\Models\Location\Traits\Relationship\CountryRelationship;

class Country extends BaseModel
{
    use CountryAttribute, CountryRelationship;
}
