<?php

namespace App\Models\Menu;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemAvailabilityRuleDay extends Model
{
    protected $guarded = [];

    protected $casts = ['day_of_week' => 'integer'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ItemAvailabilityRule::class, 'item_availability_rule_id');
    }
}
