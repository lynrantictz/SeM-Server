<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemAvailabilityRule extends BaseModel
{
    protected $guarded = ['uuid'];

    protected $casts = [
        'available_from_date' => 'date',
        'available_to_date' => 'date',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(ItemAvailabilityRuleDay::class);
    }
}
