<?php

namespace App\Models\Section;

use App\Models\BaseModel;
use App\Models\Section\Trait\Attribute\SectionAttribute;
use App\Models\Section\Trait\Relationship\SectionRelationship;
use Illuminate\Database\Eloquent\Model;

class Section extends BaseModel
{
    use SectionAttribute, SectionRelationship;
}
