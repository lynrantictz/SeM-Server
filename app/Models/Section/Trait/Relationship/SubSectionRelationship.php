<?php

namespace App\Models\Section\Trait\Relationship;

use App\Models\Section\Section;

trait SubSectionRelationship
{
    public function section()
    {
        return $this->belongsTo(Section::class);
    }
}
