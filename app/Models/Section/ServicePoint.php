<?php

namespace App\Models\Section;

use App\Models\BaseModel;
use App\Models\Business\Business;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ServicePoint extends BaseModel
{
    protected $fillable = [
        'business_id', 'section_id', 'sub_section_id', 'type', 'label',
        'display_name', 'capacity', 'notes', 'is_active',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function section(): BelongsTo { return $this->belongsTo(Section::class); }
    public function subSection(): BelongsTo { return $this->belongsTo(SubSection::class, 'sub_section_id'); }
    public function codes(): MorphMany { return $this->morphMany(Code::class, 'codable'); }
    public function activeCode() { return $this->morphOne(Code::class, 'codable')->where('is_active', true); }
}
