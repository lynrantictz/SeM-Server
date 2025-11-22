<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
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
