<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\BaseRepository;
use App\Repositories\Customer\CustomerRepository;
use Illuminate\Support\Facades\DB;

class OrderRepository extends BaseRepository
{
    const MODEL = Order::class;

    /**
     * Inputs for storing order
     */
    public function inputManipulator(Code $code, $inputs): array
    {
        return [
            'business_id' => $code->codable->business_id,
            'user_id' => null, //TODO:: update later when a registered user is making order
            'customer_id' => (new CustomerRepository())->getCustomerByPhone($inputs['phone']),
            'order_status_id' => config('constants.order_status.PENDING'),
            'payment_method_id' => $inputs['payment_method'],
            'payment_status_id' => config('constants.payment_status.PENDING'),
        ];
    }

    /**
     * Store new order
     */
    public function store(Code $code, $inputs): object
    {
        return DB::transaction(function () use ($code, $inputs) {
            //create order
            $order = $code->orders()->create($this->inputManipulator($code, $inputs));
            // create order items
            (new OrderItemRepository())->store($order, $inputs['items']);
            // update total amount
            $order->update(['total_amount' => $order->items()->sum('final_price')]);
            return $order;
        });
    }
}
