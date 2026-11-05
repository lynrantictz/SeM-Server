<?php

namespace App\Models\Location\Traits\Relationship;

use App\Models\Business\Vendor;
use App\Models\Location\City;
use App\Models\Location\District;
use App\Models\Location\Tax;
use App\Models\Payment\Currency;

trait CountryRelationship
{
    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function districts()
    {
        return $this->hasManyThrough(District::class, City::class);
    }

    public function vendors()
    {
        return $this->hasMany(Vendor::class);
    }

    public function taxes()
    {
        return $this->hasMany(Tax::class);
    }

    public function currencies()
    {
        return $this->belongsToMany(Currency::class, 'country_currencies')
            ->withPivot(['is_default', 'is_active'])
            ->withTimestamps();
    }
}
