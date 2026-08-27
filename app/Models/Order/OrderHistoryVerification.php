<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class OrderHistoryVerification extends Model
{
    protected $fillable = [
        'phone',
        'verification_code',
        'verified_at',
        'access_token',
        'expires_at',
    ];

    protected $hidden = [
        'verification_code',
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
