<?php

namespace App\Models\Location\Traits\Attribute;

trait CountryAttribute
{
    public function getFlagAttribute($value): ?string
    {
        if (!$value) {
            return null;
        }
        return url("storage/{$value}");
    }
}
