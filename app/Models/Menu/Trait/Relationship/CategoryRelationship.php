<?php

namespace App\Models\Menu\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Menu\Item;
use App\Models\Menu\CategoryAvailabilityRule;

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

    public function availabilityRules()
    {
        return $this->hasMany(CategoryAvailabilityRule::class);
    }
}
