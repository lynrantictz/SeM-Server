<?php

namespace App\Models\Menu\Trait\Relationship;

use App\Models\Business\Business;

trait CategoryRelationship
{
    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
