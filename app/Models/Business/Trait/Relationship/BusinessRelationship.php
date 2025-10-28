<?php

namespace App\Models\Business\Trait\Relationship;

use App\Models\Business\BusinessContacts;
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

    public function type()
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id', 'id');
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function contacts()
    {
        return $this->hasMany(BusinessContacts::class, 'business_id', 'id');
    }
}
