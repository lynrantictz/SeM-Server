<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderRequest;
use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\Order\OrderRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function Illuminate\Log\log;

class OrderController extends BaseController
{
    protected OrderRepository $orders;

    public function __construct(OrderRepository $orders)
    {
        $this->orders = $orders;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(OrderRequest $request)
    {
        //check if code exist
        $code = Code::query()->whereCode($request->input('code'))->first();
        if (!$code) {
            return $this->sendError('code not found', [], HTTP_NOT_FOUND);
        }
        // check if code is active
        if (!$code->is_active) {
            return $this->sendError('code is disabled. contact a hotel/restaurant', [], HTTP_NOT_FOUND);
        }
        // check if business is active
        if (!$code->codable->business->is_active) {
            return $this->sendError('Business is disabled. contact a hotel/restaurant', [], HTTP_NOT_FOUND);
        }
        $order = $this->orders->store($code, $request->except('code'));
        $data['order'] = $order;
        return $this->sendResponse($data, 'Order Placed successfully', HTTP_OK);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $number)
    {
        $order = Order::query()->whereNumber($number)->first();
        if (!$order) {
            return $this->sendError('Order not found', [], HTTP_NOT_FOUND);
        }
        $relationship = [
            'customer',
            'business',
            'business.contacts',
            'business.district',
            'business.district.city',
            'business.district.city.country',
            'status',
            'paymentMethod',
            'paymentStatus',
            'items',
            'items.item'
        ];
        $data['order'] = $order->load($relationship);
        return $this->sendResponse($data, 'Order Retrieved successfully', HTTP_OK);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
