<?php

namespace App\Models\Section;

use App\Models\BaseModel;
use App\Models\Section\Trait\Attribute\SubSectionAttribute;
use App\Models\Section\Trait\Relationship\SubSectionRelationship;

class SubSection extends BaseModel
{
    use SubSectionAttribute, SubSectionRelationship;
}
