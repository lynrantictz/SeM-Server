<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Http\Controllers\Api\V1\Order\Trait\PhoneVerificationTrait;
use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Order\ChangePhoneNumberRequest;
use App\Http\Requests\Order\OrderRequest;
use App\Http\Requests\Order\PhoneVerifyRequest;
use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\Order\OrderRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OrderController extends BaseController
{
    protected OrderRepository $orders;

    use PhoneVerificationTrait;

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
            'items.item',
            'customerVerification'
        ];
        $data['order'] = $order->load($relationship);
        return $this->sendResponse($data, 'Order Retrieved successfully', HTTP_OK);
    }

    public function verifyPhone(PhoneVerifyRequest $request, Order $order)
    {
        // check if order is available and phone is verified
        $this->verifyPhoneAndOrder($order);

        $customer_verification = $order->customerVerification()->first();

        if (!Hash::check($request->input('otp'), $customer_verification->verification_code)) {
            return $this->sendError('otp did not match', [], HTTP_BAD_REQUEST);
        }

        $data['order'] = $this->orders->verifyPhone($order);

        return $this->sendResponse($data, 'Phone verified successfully', HTTP_OK);
    }

    public function resendPhoneVerificationCode(Order $order)
    {
        // check if order is available and phone is verified
        $this->verifyPhoneAndOrder($order);
        $data['order'] = $this->orders->resendPhoneVerificationCode($order);
        return $this->sendResponse($data, 'Verification code send successfully, Please Check your WhatsApp Inbox.', HTTP_OK);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function changePhone(ChangePhoneNumberRequest $request, Order $order)
    {
        // check if order is available
        if (!$order) {
            return $this->sendError('Order not found', [], HTTP_NOT_FOUND);
        }

        $data['order'] = $this->orders->changePhone($order, $request->only('phone'));
        return $this->sendResponse($data, 'Phone number changed successfully', HTTP_OK);
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

    public function rating(Request $request, Order $order)
    {
        $this->orders->rating($order, $request->all());
        $data['order'] = $order;
        return $this->sendResponse($data, 'Thanks for your review, It will help us improve', HTTP_OK);
    }
}
