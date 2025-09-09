<?php

namespace App\Models\Menu\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Menu\Item;

trait CategoryRelationship
{
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }
}
