<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\BaseRepository;
use App\Repositories\Customer\CustomerRepository;
use App\Services\TaxCalculatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OrderRepository extends BaseRepository
{
    const MODEL = Order::class;

    use TaxCalculatorService;

    /**
     * Inputs for storing order
     */
    public function inputManipulator(Code $code, $inputs): array
    {
        $business = $code->codable->business;
        $country = $business->district?->city?->country?->iso2;
        $customer = (new CustomerRepository())->getCustomerByPhone($inputs['phone'], $country);
        return [
            'business_id' => $business->id,
            'user_id' => null, //TODO:: update later when a registered user is making order
            'customer_id' => $customer->id,
            'order_status_id' => config('constants.order_status.PENDING'),
            'payment_status_id' => config('constants.payment_status.PENDING'),
        ];
    }

    /**
     * Store new order
     */
    public function store(Code $code, $inputs): object
    {
        return DB::transaction(function () use ($code, $inputs) {
            // Lock the business row for update to prevent race conditions
            $business = $code->codable->business()->lockForUpdate()->first();
            // Increment the order number
            $business->current_order_number += 1;
            // Generate the order_number
            $orderNumber = $business->order_prefix . "-" . str_pad($business->current_order_number, 6, '0', STR_PAD_LEFT);
            // Extra safety: check if this order number already exists
            while ($code->orders()->where('number', $orderNumber)->exists()) {
                $business->current_order_number += 1;
                $orderNumber = $business->order_prefix . "-" . str_pad($business->current_order_number, 6, '0', STR_PAD_LEFT);
            }
            $business->save();
            //create order
            $order = $code->orders()->create(array_merge(
                $this->inputManipulator($code, $inputs),
                ['number' => $orderNumber]
            ));
            // create order items
            (new OrderItemRepository())->store($order, $inputs['items']);
            //calculate total amount
            $order_total_amount = $order->items()->sum('total_amount');
            $taxCalculator = $this->calculateTax($business->district->city->country, $order_total_amount);
            /**
             * update tax details and amount
             * tax_id
             * tax_amount
             * total_amount
             */
            $order->update($taxCalculator);

            (new OrderCustomerVerificationRepository())->storeOrUpdatePhone($order);
            return $order;
        });
    }

    public function verifyPhone(Order $order)
    {
        return DB::transaction(function () use ($order) {
            $order->update([
                'phone_verified_at' => now()
            ]);
            return $order;
        });
    }

    /**
     * Resend Phone Verification Code
     * Order $order
     */
    public function resendPhoneVerificationCode(Order $order)
    {
        return DB::transaction(function () use ($order) {
            $phone = $order->customerVerification->phone;
            $randomCode = random_int(1000, 9999);
            $verificationInputs = [
                'verification_code' => Hash::make($randomCode),
                'expires_at' => now()->addMinutes(10),
            ];
            $order->customerVerification()->update($verificationInputs);

            // The code is delivered by the configured messaging integration.

            \Log::info("Resent verification code {$randomCode} to phone {$phone} for order {$order->number}");
            return $order;
        });
    }

    /**
     * Change Phone Number
     */
    public function changePhone(Order $order, $input)
    {
        return DB::transaction(function () use ($order, $input) {
            $country = $order->business?->district?->city?->country?->iso2;
            $customer = (new CustomerRepository())->getCustomerByPhone($input['phone'], $country);

            $order->forceFill(['customer_id' => $customer->id])->save();
            (new OrderCustomerVerificationRepository())->storeOrUpdatePhone($order, $input['phone'], $country);

            return $order->refresh();
        });
    }

    public function rating(Order $order, $inputs)
    {
        return DB::transaction(function () use ($order, $inputs) {
            return $order->update($inputs);
        });
    }
}
