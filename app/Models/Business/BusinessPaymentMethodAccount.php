<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessPaymentMethodAccount extends BaseModel
{
    protected $fillable = [
        'business_payment_method_id', 'label', 'bank_name', 'account_number',
        'account_holder_name', 'branch_name', 'currency', 'is_default',
        'is_enabled', 'sort_order', 'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_enabled' => 'boolean',
    ];

    public function businessPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(BusinessPaymentMethod::class);
    }
}
