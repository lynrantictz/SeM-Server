<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class OrderingChannel extends BaseModel
{
    protected $fillable = ['slug', 'name', 'description', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public static function activeSlugs(): array
    {
        return static::query()->where('is_active', true)->pluck('slug')->all();
    }
}
