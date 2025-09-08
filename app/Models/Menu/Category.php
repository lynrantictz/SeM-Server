<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use App\Models\Menu\Trait\Attribute\CategoryAttribute;
use App\Models\Menu\Trait\Relationship\CategoryRelationship;

class Category extends BaseModel
{
    use CategoryAttribute, CategoryRelationship;
}
