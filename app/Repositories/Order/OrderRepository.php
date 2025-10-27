<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\BaseRepository;
use App\Repositories\Customer\CustomerRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderRepository extends BaseRepository
{
    const MODEL = Order::class;

    /**
     * Inputs for storing order
     */
    public function inputManipulator(Code $code, $inputs): array
    {
        $business = $code->codable->business;
        $customer = (new CustomerRepository())->getCustomerByPhone($inputs['phone']);
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
            // update total amount and generate order number
            $order->update([
                'total_amount' => $order->items()->sum('total_amount')
            ]);
            return $order;
        });
    }
}
