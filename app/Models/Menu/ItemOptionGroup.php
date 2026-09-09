<?php

namespace App\Models\Menu;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemOptionGroup extends BaseModel
{
    protected $guarded = ['uuid'];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'min_selections' => 'integer',
        'max_selections' => 'integer',
        'sort_order' => 'integer',
    ];

    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function options(): HasMany { return $this->hasMany(ItemOption::class)->orderBy('sort_order'); }
}
