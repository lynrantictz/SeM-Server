<?php

namespace App\Models\Payment;

use App\Models\Auth\User;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
