<?php

namespace App\Models\Business\Trait\Attribute;

trait VendorAttribute
{
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = preg_replace('/\D+/', '', (string) $value);
    }
}
