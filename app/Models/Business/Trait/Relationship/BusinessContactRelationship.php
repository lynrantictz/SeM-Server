<?php

namespace App\Models\Business\Trait\Relationship;

use App\Models\Business\Business;

trait BusinessContactRelationship
{
    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
