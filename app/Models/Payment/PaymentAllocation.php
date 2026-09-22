<?php

namespace App\Models\Payment;

use App\Models\BaseModel;
use App\Models\Business\Business;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends BaseModel
{
    protected $fillable = [
        'payment_id', 'business_id', 'gross_amount', 'commission_base_amount', 'commission_rate',
        'commission_amount', 'gateway_fee_amount', 'business_payable_amount', 'currency', 'calculation',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'commission_base_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'gateway_fee_amount' => 'decimal:2',
        'business_payable_amount' => 'decimal:2',
        'calculation' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
