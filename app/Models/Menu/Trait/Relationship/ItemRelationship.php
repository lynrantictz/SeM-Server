<?php

namespace App\Models\Menu\Trait\Relationship;

use App\Models\Menu\Category;
use App\Models\Menu\ItemPrice;

trait ItemRelationship
{
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function prices()
    {
        return $this->hasMany(ItemPrice::class);
    }
}
