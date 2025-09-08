<?php

namespace App\Models\Auth\Trait\Relationship;

use App\Models\Business\Business;

trait UserRelationship
{
    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
