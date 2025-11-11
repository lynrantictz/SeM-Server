<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderCustomerVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OrderCustomerVerificationRepository
{
    const MODEL = OrderCustomerVerification::class;

    public function storeOrUpdatePhone(Order $order, $phone = null)
    {
        $random_code = rand(1000, 9999);
        $hashed_random_code = Hash::make($random_code);
        $verification_inputs = [
            'phone' => !$phone ? $order->customer->phone : $phone,
            'verification_code' => $hashed_random_code
        ];
        // Generate for verification
        return DB::transaction(function () use ($order, $verification_inputs, $random_code) {
            $order->customerVerification()->updateOrCreate(
                ['order_id' => $order->id],
                $verification_inputs
            );
            Log::info($random_code);
            return $order;
        });
    }
}
