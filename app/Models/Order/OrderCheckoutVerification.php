<?php

namespace App\Models\Order;

use App\Models\BaseModel;

class OrderCheckoutVerification extends BaseModel
{
    protected $fillable = [
        'code_id',
        'channel',
        'phone',
        'customer_id',
        'checkout_payload',
        'verification_code',
        'code_sent_at',
        'attempts',
        'resend_count',
        'expires_at',
        'verified_at',
        'order_id',
    ];

    protected $casts = [
        'checkout_payload' => 'array',
        'expires_at' => 'datetime',
        'code_sent_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'verification_code',
        'checkout_payload',
    ];
}
