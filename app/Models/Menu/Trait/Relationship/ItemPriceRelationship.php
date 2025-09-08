<?php

namespace App\Models\Menu\Trait\Relationship;

use App\Models\Menu\Item;

trait ItemPriceRelationship
{
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
