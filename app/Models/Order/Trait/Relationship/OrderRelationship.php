<?php

namespace App\Models\Order\Trait\Relationship;

use App\Models\Business\Business;
use App\Models\Business\OrderingChannel;
use App\Models\Customer\Customer;
use App\Models\Order\OrderCustomerVerification;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderStatus;
use App\Models\Order\OrderStatusHistory;
use App\Models\Location\Tax;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentMethod;
use App\Models\Payment\PaymentStatus;
use App\Models\Auth\User;
use App\Models\Section\ServicePoint;
use App\Models\Order\OrderStaffNote;
use App\Models\Order\OrderWorkLock;

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

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function statusHistories()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function paymentStatus()
    {
        return $this->belongsTo(PaymentStatus::class, 'payment_status_id');
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function customerVerification()
    {
        return $this->hasOne(OrderCustomerVerification::class, 'order_id', 'id');
    }

    public function payment()
    {
        return $this->hasOne(Payment::class, 'order_id', 'id');
    }

    public function servicePoint()
    {
        return $this->belongsTo(ServicePoint::class);
    }

    public function orderingChannel()
    {
        return $this->belongsTo(OrderingChannel::class, 'channel', 'slug');
    }

    public function staffNotes()
    {
        return $this->hasMany(OrderStaffNote::class)->orderBy('created_at');
    }

    public function workLock()
    {
        return $this->hasOne(OrderWorkLock::class);
    }
}
