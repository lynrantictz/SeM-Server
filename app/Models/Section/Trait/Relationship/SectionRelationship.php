<?php

namespace App\Models\Section\Trait\Relationship;

use App\Models\Section\SubSection;

trait SectionRelationship
{
    public function subs()
    {
        return $this->hasMany(SubSection::class, 'section_id', 'id');
    }
}
