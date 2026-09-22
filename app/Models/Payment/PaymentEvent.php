<?php

namespace App\Models\Payment;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends BaseModel
{
    protected $fillable = [
        'payment_id', 'provider', 'event_type', 'event_reference', 'signature_verified',
        'payload', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'signature_verified' => 'boolean',
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
