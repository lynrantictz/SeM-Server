<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryAvailabilityRule extends BaseModel
{
    protected $guarded = ['uuid'];

    protected $casts = [
        'available_from_date' => 'date',
        'available_to_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function days(): HasMany { return $this->hasMany(CategoryAvailabilityRuleDay::class); }
}
