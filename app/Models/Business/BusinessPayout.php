<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentAllocation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPayout extends BaseModel
{
    protected $fillable = [
        'business_id', 'payment_id', 'payment_allocation_id', 'payout_account_id', 'gateway',
        'gross_amount', 'commission_amount', 'gateway_fee_amount', 'net_amount', 'currency',
        'status', 'idempotency_key', 'external_reference', 'provider_reference', 'hold_reason',
        'failure_reason', 'request_payload', 'response_payload', 'submitted_at', 'completed_at',
        'failed_at', 'reversed_at',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'gateway_fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function allocation(): BelongsTo { return $this->belongsTo(PaymentAllocation::class, 'payment_allocation_id'); }
    public function payoutAccount(): BelongsTo { return $this->belongsTo(BusinessPayoutAccount::class, 'payout_account_id'); }
}
