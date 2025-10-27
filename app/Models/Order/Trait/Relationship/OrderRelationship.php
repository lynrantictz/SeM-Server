<?php

namespace App\Models\Order\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Customer\Customer;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderStatus;
use App\Models\Payment\PaymentMethod;
use App\Models\Payment\PaymentStatus;

trait OrderRelationship
{
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function status()
    {
        return $this->belongsTo(OrderStatus::class, 'order_status_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function paymentStatus()
    {
        return $this->belongsTo(PaymentStatus::class, 'payment_status_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }
}
