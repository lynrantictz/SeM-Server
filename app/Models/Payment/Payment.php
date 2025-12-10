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
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}
