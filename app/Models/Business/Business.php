<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use App\Models\Business\Trait\Attribute\BusinessAttribute;
use App\Models\Business\Trait\Relationship\BusinessRelationship;

class Business extends BaseModel
{
    use BusinessAttribute, BusinessRelationship;
}
