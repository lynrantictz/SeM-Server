<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use App\Models\Business\Trait\Attribute\BusinessAttribute;
use App\Models\Business\Trait\Relationship\BusinessRelationship;
use Illuminate\Support\Facades\Storage;

class Business extends BaseModel
{
    use BusinessAttribute, BusinessRelationship;

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        return Storage::disk($this->logo_disk ?: 'public')->url($this->logo_path);
    }
}
