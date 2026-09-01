<?php

namespace App\Models\Auth\Trait\Attribute;

use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Hash;

trait UserAttribute
{
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    public function setPhoneAttribute($value)
    {
        if ($value === null || trim((string) $value) === '') {
            $this->attributes['phone'] = null;

            return;
        }

        $this->attributes['phone'] = app(PhoneNumberNormalizer::class)->normalize(
            $value,
            request()->input('countryCode'),
        );
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
