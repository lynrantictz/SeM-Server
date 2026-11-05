<?php

namespace App\Models\Location\Traits\Attribute;

trait CountryAttribute
{
    public function getFlagAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        // Country flags are checked-in static files under public/flags, not
        // uploaded files under storage/app/public.
        return asset(ltrim($value, '/'));
    }
}
