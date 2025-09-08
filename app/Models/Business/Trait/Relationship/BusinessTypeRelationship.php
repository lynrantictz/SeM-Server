<?php

namespace App\Models\Business\Trait\Relationship;

use App\Models\Business\Business;

trait BusinessTypeRelationship
{
    public function businesses()
    {
        return $this->hasMany(Business::class);
    }
}
