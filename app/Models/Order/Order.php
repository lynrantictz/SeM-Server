<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Order\Trait\Attribute\OrderAttribute;
use App\Models\Order\Trait\Relationship\OrderRelationship;

class Order extends BaseModel
{
    use OrderAttribute, OrderRelationship;

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if ($order->business) app(\App\Services\Business\BusinessBillingService::class)->assertCanOrder($order->business);
        });
        static::created(fn (Order $order) => app(\App\Services\Business\BusinessBillingService::class)->syncFee($order));
        static::updated(function (Order $order) {
            if ($order->wasChanged(['total_items_amount', 'order_status_id', 'payment_status_id'])) app(\App\Services\Business\BusinessBillingService::class)->syncFee($order);
        });
    }

    protected $hidden = [
        'receipt_token',
    ];

    protected $casts = [
        'order_status_id' => 'integer',
        'payment_status_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'scheduled_for' => 'datetime',
        'preparation_starts_at' => 'datetime',
    ];

    protected $appends = ['created_at_formatted'];
}
