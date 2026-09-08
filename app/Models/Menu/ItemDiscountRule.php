<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemDiscountRule extends BaseModel
{
    protected $guarded = ['uuid'];
    protected $casts = ['discount_percentage' => 'float', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
}
