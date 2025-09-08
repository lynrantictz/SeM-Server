<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use App\Models\Menu\Trait\Attribute\ItemAttribute;
use App\Models\Menu\Trait\Relationship\ItemRelationship;

class Item extends BaseModel
{
    use ItemAttribute, ItemRelationship;
}
