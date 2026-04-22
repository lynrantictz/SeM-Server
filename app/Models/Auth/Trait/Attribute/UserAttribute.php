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
        $this->attributes['phone'] = setPhoneFormat(request()->input('countryCode'), $value);
    }

    public function setNameAttribute($value)
    {
        $this->attributes['name'] = ucwords(strtolower($value));
    }

    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = strtolower($value);
    }
}
