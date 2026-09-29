<?php

namespace App\Models;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessInquiry extends BaseModel
{
    protected $fillable = [
        'source',
        'package_code',
        'package_name',
        'name',
        'business_name',
        'email',
        'phone',
        'message',
        'status',
        'assigned_to_user_id',
        'contacted_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'contacted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
