<?php

namespace App\Models\Business\Trait\Relationship;

use App\Models\Business\BusinessContacts;
use App\Models\Business\BusinessType;
use App\Models\Business\Vendor;
use App\Models\Location\District;
use App\Models\Menu\Category;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\BusinessOpeningHour;
use App\Models\Business\BusinessPromotion;
use App\Models\Business\Timezone;
use App\Models\Business\OrderingChannel;
use App\Models\Business\BusinessPaymentSetting;

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

    public function complianceDocuments()
    {
        return $this->hasMany(ComplianceDocument::class);
    }

    public function openingHours()
    {
        return $this->hasMany(BusinessOpeningHour::class);
    }

    public function promotions()
    {
        return $this->hasMany(BusinessPromotion::class);
    }

    public function timezoneDefinition()
    {
        return $this->belongsTo(Timezone::class, 'timezone_id');
    }

    public function orderingChannels()
    {
        return $this->belongsToMany(OrderingChannel::class, 'business_ordering_channels')
            ->withPivot('is_enabled')
            ->withTimestamps();
    }

    public function paymentSetting()
    {
        return $this->hasOne(BusinessPaymentSetting::class);
    }
}
