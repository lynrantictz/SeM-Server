<?php

namespace App\Models\Transaction\Trait\Relationship;

use App\Models\Order\Order;
use App\Models\Payment\PaymentGateway;

trait TransactionRelationship
{

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

}
