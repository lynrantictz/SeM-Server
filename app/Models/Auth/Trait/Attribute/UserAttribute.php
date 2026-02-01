<?php

namespace App\Models\Auth\Trait\Attribute;

use Illuminate\Support\Facades\Hash;

trait UserAttribute
{
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }
}
