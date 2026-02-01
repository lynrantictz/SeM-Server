<?php

namespace App\Models\Auth\Trait\Attribute;

use Illuminate\Support\Facades\Hash;

trait UserAttribute
{
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    public function setPhoneAttribute($value)
    {
        // remove spaces from phone number before saving
        // remove 0 if it's the first character
        // e.g 0712 345 678 -> 712345678
        // get request()->input('country_code') if exists
        // then connect country code with phone number
        // e.g 255712345678
        $phone = str_replace(' ', '', $value);
        if (substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }
        if ($countryCode = request()->input('country_code')) {
            $phone = $countryCode . $phone;
        }
        $this->attributes['phone'] = $phone;
    }
}
