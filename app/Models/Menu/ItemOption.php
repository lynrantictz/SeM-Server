<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemOption extends BaseModel
{
    protected $guarded = ['uuid'];

    protected $casts = [
        'price_adjustment' => 'float',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function group(): BelongsTo { return $this->belongsTo(ItemOptionGroup::class, 'item_option_group_id'); }
}
