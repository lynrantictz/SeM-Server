<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderCustomerVerification;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrderCustomerVerificationRepository
{
    const MODEL = OrderCustomerVerification::class;

    public function storeOrUpdatePhone(Order $order, string|int|null $phone = null, ?string $country = null): Order
    {
        $randomCode = random_int(1000, 9999);
        $canonicalPhone = (new PhoneNumberNormalizer())->normalize(
            $phone ?? $order->customer?->phone,
            $country ?? $order->business?->district?->city?->country?->iso2
        );
        $verificationInputs = [
            'phone' => $canonicalPhone,
            'verification_code' => Hash::make($randomCode),
            'expires_at' => now()->addMinutes(10),
        ];

        return DB::transaction(function () use ($order, $verificationInputs) {
            $order->customerVerification()->updateOrCreate(
                ['order_id' => $order->id],
                $verificationInputs
            );

            // The code is delivered by the configured messaging integration;
            // never write OTPs or phone numbers to application logs.
            return $order;
        });
    }
}
