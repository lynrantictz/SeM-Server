<?php

namespace App\Models\Payment;

use App\Models\Auth\User;
use App\Models\BaseModel;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends BaseModel
{
    protected $fillable = [
        'order_id',
        'initiated_by_user_id',
        'external_id',
        'idempotency_key',
        'transaction_id',
        'account_number',
        'provider',
        'initiation_source',
        'amount',
        'currency',
        'status',
        'confirmation_source',
        'confirmed_by_user_id',
        'confirmed_at',
        'expires_at',
        'paid_at',
        'failed_at',
        'failure_reason',
        'request_payload',
        'response_payload',
        'metadata',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'metadata' => 'array',
        'confirmed_at' => 'datetime',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function allocation(): HasOne
    {
        return $this->hasOne(PaymentAllocation::class);
    }
}
