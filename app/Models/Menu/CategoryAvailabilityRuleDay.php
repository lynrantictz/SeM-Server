<?php

namespace App\Models\Menu;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryAvailabilityRuleDay extends Model
{
    protected $guarded = [];
    protected $casts = ['day_of_week' => 'integer'];
    public function rule(): BelongsTo { return $this->belongsTo(CategoryAvailabilityRule::class, 'category_availability_rule_id'); }
}
