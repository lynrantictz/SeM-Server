<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class OrderPlatformFee extends BaseModel
{
    protected $casts = ['rate' => 'decimal:2', 'base_amount' => 'decimal:2', 'amount' => 'decimal:2', 'billable_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $fee) {
            abort_if($fee->isDirty(['order_id', 'business_id', 'business_commission_policy_id', 'rate', 'currency', 'reason']), 409, 'An order fee must retain its original commission agreement and applied rate.');
        });
    }

    public function commissionPolicy()
    {
        return $this->belongsTo(BusinessCommissionPolicy::class, 'business_commission_policy_id');
    }

    public function order()
    {
        return $this->belongsTo(\App\Models\Order\Order::class);
    }
}
