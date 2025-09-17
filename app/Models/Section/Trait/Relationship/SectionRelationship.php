<?php

namespace App\Models\Section\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Section\Code;
use App\Models\Section\SubSection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

trait SectionRelationship
{
    /**
     * Get all of the subsections for the section.
     */
    public function subs(): HasMany
    {
        return $this->hasMany(SubSection::class, 'section_id', 'id');
    }

    /**
     * Get the section's code.
     */
    public function code(): MorphOne
    {
        return $this->morphOne(Code::class, 'codable');
    }

    /**
     * Get the business that owns the section.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
