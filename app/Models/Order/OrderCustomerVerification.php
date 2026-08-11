<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class OrderCustomerVerification extends Model
{
    protected $fillable = [
        'order_id',
        'phone',
        'verification_code',
        'expires_at',
    ];

    protected $hidden = [
        'verification_code',
    ];
}
