<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Exceptions\InvalidPhoneNumberException;
use App\Http\Controllers\Api\V1\Order\Trait\PhoneVerificationTrait;
use App\Http\Controllers\Api\BaseController;
use App\Models\Business\OrderingChannel;
use App\Http\Requests\Order\ChangePhoneNumberRequest;
use App\Http\Requests\Order\OrderRequest;
use App\Http\Requests\Order\PhoneVerifyRequest;
use App\Http\Requests\Order\SendOrderHistoryVerificationRequest;
use App\Http\Requests\Order\VerifyOrderHistoryVerificationRequest;
use App\Models\Order\Order;
use App\Models\Order\OrderHistoryVerification;
use App\Models\Section\Code;
use App\Repositories\Customer\CustomerRepository;
use App\Repositories\Order\OrderRepository;
use App\Services\PhoneNumberNormalizer;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends BaseController
{
    protected OrderRepository $orders;
    protected CustomerRepository $customers;

    use PhoneVerificationTrait;

    public function __construct(OrderRepository $orders, CustomerRepository $customers)
    {
        $this->orders = $orders;
        $this->customers = $customers;
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
        $channel = $request->input('channel', 'dine_in');
        if (!in_array($channel, OrderingChannel::activeSlugs(), true)) {
            return $this->sendError('The ordering channel is invalid.', ['channel' => $channel], HTTP_UNPROCESSABLE_ENTITY);
        }
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
        if (!$this->channelEnabled($code->codable->business, $channel)) {
            return $this->sendError('This ordering channel is not enabled for this business.', ['channel' => $channel], HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($code->codable instanceof \App\Models\Section\ServicePoint && !$code->codable->is_active) {
            return $this->sendError('This table, room, or service point is not currently accepting orders.', [], HTTP_UNPROCESSABLE_ENTITY);
        }
        $businessStatus = app(MenuAvailabilityService::class)->businessStatus($code->codable->business);
        if (!$businessStatus['is_open_now']) {
            return $this->sendError($businessStatus['reason'], ['menu_status' => $businessStatus], HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $order = $this->orders->store($code, $request->except('code'), $channel);
        } catch (InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        } catch (ValidationException $exception) {
            return $this->sendError('One or more menu items cannot be ordered.', $exception->errors(), HTTP_UNPROCESSABLE_ENTITY);
        }

        $data['order'] = $order;
        return $this->sendResponse($data, 'Order Placed successfully', HTTP_OK);
    }

    private function channelEnabled($business, string $channel): bool
    {
        $setting = $business->orderingChannels()->where('slug', $channel)->where('ordering_channels.is_active', true)->first();
        return $setting ? (bool) $setting->pivot->is_enabled : false;
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
            'items.options',
            'items.item',
            'customerVerification',
            'payment',
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

        try {
            $data['order'] = $this->orders->changePhone($order, $request->only('phone'));
        } catch (InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->sendResponse($data, 'Phone number changed successfully', HTTP_OK);
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * Send a short-lived code before a guest can access their order history.
     */
    public function sendOrderHistoryVerification(SendOrderHistoryVerificationRequest $request)
    {
        try {
            $phone = (new PhoneNumberNormalizer())->normalize(
                $request->input('phone'),
                $request->input('country')
            );
        } catch (InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $otp = (string) random_int(1000, 9999);

        OrderHistoryVerification::query()->updateOrCreate(
            ['phone' => $phone],
            [
                'verification_code' => Hash::make($otp),
                'verified_at' => null,
                'access_token' => null,
                'expires_at' => now()->addMinutes(10),
            ]
        );

        $message = 'Verification code delivery is not configured.';
        if (app()->environment(['local', 'testing'])) {
            Log::info('Order history verification code (local testing only)', ['otp' => $otp]);
            $message = 'Verification code generated. Check the Laravel log during local testing.';
        }

        return $this->sendResponse([], $message, HTTP_OK);
    }

    /**
     * Confirm the OTP and issue a short-lived token for retrieving a guest's orders.
     */
    public function verifyOrderHistoryVerification(VerifyOrderHistoryVerificationRequest $request)
    {
        try {
            $phone = (new PhoneNumberNormalizer())->normalize(
                $request->input('phone'),
                $request->input('country')
            );
        } catch (InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $verification = OrderHistoryVerification::query()->where('phone', $phone)->first();

        if (! $verification || $verification->expires_at->isPast()) {
            return $this->sendError('This verification code has expired. Request a new code and try again.', [], HTTP_BAD_REQUEST);
        }

        if (! Hash::check($request->input('otp'), $verification->verification_code)) {
            return $this->sendError('The verification code is incorrect.', [], HTTP_BAD_REQUEST);
        }

        $token = Str::random(64);
        $verification->update([
            'verified_at' => now(),
            'access_token' => Hash::make($token),
            'expires_at' => now()->addMinutes(15),
        ]);

        return $this->sendResponse(['token' => $token], 'Phone number verified successfully.', HTTP_OK);
    }

    /**
     * Return order history only after the matching phone number has been verified.
     */
    public function getOrdersByPhone(Request $request, string $phone)
    {
        $country = $request->query('country') ?? $request->query('countryCode');
        $token = $request->query('token');

        if (is_array($country) || is_array($token)) {
            return $this->sendError('The country code is invalid.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $canonicalPhone = (new PhoneNumberNormalizer())->normalize($phone, $country);
        } catch (InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $verification = OrderHistoryVerification::query()->where('phone', $canonicalPhone)->first();
        if (! $token || ! $verification || ! $verification->verified_at || $verification->expires_at->isPast() || ! Hash::check($token, $verification->access_token ?? '')) {
            return $this->sendError('Verify this phone number before viewing its order history.', [], HTTP_UNAUTHORIZED);
        }

        $customer = $this->customers->findCustomerByPhone($canonicalPhone);

        if (!$customer || !$customer->orders()->exists()) {
            return $this->sendError('No orders found for this phone number', [], HTTP_NOT_FOUND);
        }

        $orders = $customer->orders()
            ->with([
                'customer',
                'business',
                'business.district',
                'business.district.city',
                'business.district.city.country',
                'status',
                'paymentMethod',
                'paymentStatus',
                'items',
                'items.item',
            ])
            ->latest()
            ->get();

        // The matching phone is the lookup credential, not response data.
        $orders->each(fn (Order $order) => $order->customer?->makeHidden(['phone']));

        return $this->sendResponse($orders, 'Orders retrieved successfully', HTTP_OK);
    }

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
