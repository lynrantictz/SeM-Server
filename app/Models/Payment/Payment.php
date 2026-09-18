<?php

namespace App\Models\Payment;

use App\Models\BaseModel;

class Payment extends BaseModel
{
    protected $fillable = [
        'order_id',
        'external_id',
        'transaction_id',
        'account_number',
        'provider',
        'amount',
        'currency',
        'status',
        'confirmation_source',
        'confirmed_by_user_id',
        'confirmed_at',
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'confirmed_at' => 'datetime',
    ];
}
