<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPaymentSetting extends BaseModel
{
    protected $fillable = [
        'business_id', 'provider', 'currency', 'commission_rate', 'commission_basis', 'fee_bearer',
        'settlement_mode', 'is_checkout_enabled', 'is_settlement_enabled', 'payout_provider',
        'payout_account_number', 'payout_recipient_reference',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'is_checkout_enabled' => 'boolean',
        'is_settlement_enabled' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
