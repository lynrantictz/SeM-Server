<?php

namespace App\Models\Menu\Trait\Relationship;

use App\Models\Menu\Category;
use App\Models\Menu\ItemPrice;
use App\Models\Menu\ItemAvailabilityRule;
use App\Models\Menu\ItemDiscountRule;
use App\Models\Menu\ItemOptionGroup;

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

    public function availabilityRules()
    {
        return $this->hasMany(ItemAvailabilityRule::class);
    }

    public function discountRules()
    {
        return $this->hasMany(ItemDiscountRule::class);
    }

    public function optionGroups()
    {
        return $this->hasMany(ItemOptionGroup::class)->orderBy('sort_order');
    }
}
