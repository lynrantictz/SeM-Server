<?php

namespace App\Models\Business\Trait\Attribute;

use App\Models\Location\Country;

trait VendorAttribute
{

    public function setPhoneAttribute($value)
    {
        $phone = str_replace(' ', '', $value);
        if (substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }
        $country = Country::find(request()->input('country_id'));
        $phone = $country->phone_code . $phone;
        $this->attributes['phone'] = $phone;
    }
}
