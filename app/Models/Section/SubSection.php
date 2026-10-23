<?php

namespace App\Models\Section;

use App\Models\BaseModel;
use App\Models\Section\Trait\Attribute\SubSectionAttribute;
use App\Models\Section\Trait\Relationship\SubSectionRelationship;

class SubSection extends BaseModel
{
    use SubSectionAttribute, SubSectionRelationship;

    protected $casts = [
        'is_active' => 'boolean',
        'is_system_managed' => 'boolean',
    ];
}
