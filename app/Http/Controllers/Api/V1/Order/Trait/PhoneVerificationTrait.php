<?php

namespace App\Http\Controllers\Api\V1\Order\Trait;

use App\Models\Order\Order;

trait PhoneVerificationTrait
{
    public function verifyPhoneAndOrder(Order $order)
    {
        // /Users/Hamis/Herd/sem/server/app/Http/Controllers/Api/V1/Order/Trait/PhoneVerificationTrait.php
        // check if order is available
        if (!$order) {
            return $this->sendError('Order not found', [], HTTP_NOT_FOUND);
        }
        // check if order is verified
        if ($order->phone_verified_at) {
            return $this->sendError('Phone number verified before', [], HTTP_FORBIDDEN);
        }
    }
}
