<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use App\Models\Menu\Trait\Attribute\ItemPriceAttribute;
use App\Models\Menu\Trait\Relationship\ItemPriceRelationship;

class ItemPrice extends BaseModel
{
    use ItemPriceAttribute, ItemPriceRelationship;
}
