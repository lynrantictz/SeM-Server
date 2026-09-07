<?php

namespace App\Models\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessOpeningHour extends Model
{
    protected $guarded = [];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_closed' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
