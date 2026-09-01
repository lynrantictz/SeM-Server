<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class BusinessStaffRole extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
