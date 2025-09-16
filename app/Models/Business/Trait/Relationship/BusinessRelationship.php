<?php

namespace App\Models\Business\Trait\Relationship;

use App\Models\Business\BusinessType;
use App\Models\Business\Vendor;
use App\Models\Location\District;
use App\Models\Menu\Category;

trait BusinessRelationship
{
    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function businessType()
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }
}
