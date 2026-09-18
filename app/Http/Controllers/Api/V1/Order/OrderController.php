<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Exceptions\InvalidPhoneNumberException;
use App\Http\Controllers\Api\V1\Order\Trait\PhoneVerificationTrait;
use App\Http\Controllers\Api\BaseController;
use App\Models\Business\OrderingChannel;
use App\Models\Business\Business;
use App\Http\Requests\Order\ChangePhoneNumberRequest;
use App\Http\Requests\Order\OrderRequest;
use App\Http\Requests\Order\PhoneVerifyRequest;
use App\Http\Requests\Order\SendOrderHistoryVerificationRequest;
use App\Http\Requests\Order\VerifyOrderHistoryVerificationRequest;
use App\Models\Order\Order;
use App\Models\Order\OrderCheckoutVerification;
use App\Models\Order\OrderHistoryVerification;
use App\Models\Order\OrderCustomerSession;
use App\Models\Order\OrderStatus;
use App\Models\Payment\PaymentStatus;
use App\Models\Section\Code;
use App\Repositories\Customer\CustomerRepository;
use App\Repositories\Order\OrderRepository;
use App\Services\PhoneNumberNormalizer;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

class OrderController extends BaseController
{
    private const CHECKOUT_SESSION_MINUTES = 5;
    private const CHECKOUT_CODE_MINUTES = 5;

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
        $code = Code::query()
            ->with('codable.business.district.city.country')
            ->whereCode($request->input('code'))
            ->first();
        if (!$code) {
            return $this->sendError('code not found', [], HTTP_NOT_FOUND);
        }
        if (!in_array($channel, OrderingChannel::activeSlugs(), true)) {
            return $this->sendError('The ordering channel is invalid.', ['channel' => $channel], HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($reason = $this->checkoutUnavailableReason($code, $channel)) {
            return $this->sendError($reason, [], HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $businessCountry = $code->codable->business->district?->city?->country?->iso2;
            $phone = (new PhoneNumberNormalizer())->normalize($request->input('phone'), $businessCountry);
        } catch (InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        // No WhatsApp/SMS provider is configured in this application yet.
        // In local development, expose the OTP only through the Laravel log.
        if (!app()->environment(['local', 'testing'])) {
            return $this->sendError('Phone verification delivery is not configured. The order was not created.', [], 503);
        }

        $otp = (string) random_int(1000, 9999);
        $checkout = OrderCheckoutVerification::create([
            'code_id' => $code->id,
            'channel' => $channel,
            'phone' => $phone,
            'checkout_payload' => [
                'items' => collect($request->input('items'))->map(fn (array $item) => [
                    'uuid' => $item['uuid'],
                    'quantity' => $item['quantity'],
                    'comment' => $item['comment'] ?? null,
                    'options' => collect($item['options'] ?? [])->map(fn ($option) => [
                        'uuid' => is_array($option) ? $option['uuid'] : $option,
                    ])->values()->all(),
                ])->values()->all(),
            ],
            'verification_code' => Hash::make($otp),
            'code_sent_at' => now(),
            'expires_at' => now()->addMinutes(self::CHECKOUT_CODE_MINUTES),
        ]);

        Log::info('Order checkout verification code (local testing only)', [
            'checkout_uuid' => $checkout->uuid,
            'otp' => $otp,
        ]);

        return $this->sendResponse([
            'verification' => [
                'uuid' => $checkout->uuid,
                'expires_at' => $checkout->expires_at,
                'session_expires_at' => $checkout->created_at->copy()->addMinutes(self::CHECKOUT_SESSION_MINUTES),
            ],
        ], 'Verification code generated. Check the Laravel log during local testing.', HTTP_OK);
    }

    public function confirmCheckoutVerification(PhoneVerifyRequest $request, string $uuid)
    {
        try {
            $result = DB::transaction(function () use ($request, $uuid) {
                $checkout = OrderCheckoutVerification::query()
                    ->where('uuid', $uuid)
                    ->lockForUpdate()
                    ->first();

                if (!$checkout) {
                    return ['error' => 'Checkout verification was not found. Please start checkout again.', 'status' => HTTP_NOT_FOUND];
                }
                // Make a successful confirmation safe to retry if the browser
                // did not receive the first response.
                if ($checkout->order_id && Hash::check($request->input('otp'), $checkout->verification_code)) {
                    $completedOrder = Order::query()->find($checkout->order_id);
                    return $completedOrder
                        ? ['order' => $completedOrder, 'guest_session' => $this->issueGuestOrderSession((int) $completedOrder->customer_id, (int) $completedOrder->business_id)]
                        : ['error' => 'The order linked to this verification could not be found.', 'status' => HTTP_NOT_FOUND];
                }
                if ($checkout->created_at->copy()->addMinutes(self::CHECKOUT_SESSION_MINUTES)->isPast()) {
                    return ['error' => 'This checkout session has expired. Start checkout again.', 'status' => 410];
                }
                if ($checkout->expires_at->isPast()) {
                    return ['error' => 'This verification code has expired. Request a new code.', 'status' => 410];
                }
                if ($checkout->attempts >= 5) {
                    return ['error' => 'Too many incorrect codes. Request a new code to continue.', 'status' => 429];
                }
                if (!Hash::check($request->input('otp'), $checkout->verification_code)) {
                    $checkout->increment('attempts');
                    return ['error' => 'The verification code is incorrect.', 'status' => HTTP_BAD_REQUEST];
                }

                $code = Code::query()
                    ->with('codable.business.district.city.country')
                    ->find($checkout->code_id);
                if (!$code) {
                    return ['error' => 'This menu is no longer available. Please scan its QR code again.', 'status' => HTTP_UNPROCESSABLE_ENTITY];
                }

                if ($reason = $this->checkoutUnavailableReason($code, $checkout->channel)) {
                    return ['error' => $reason, 'status' => HTTP_UNPROCESSABLE_ENTITY];
                }

                $payload = $checkout->checkout_payload;
                $payload['phone'] = $checkout->phone;
                $order = $this->orders->store($code, $payload, $checkout->channel);
                $order->forceFill(['phone_verified_at' => now()])->save();

                $checkout->forceFill([
                    'verified_at' => now(),
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    // The canonical phone now lives on customers. Keep the
                    // checkout row free of copied personal data after OTP.
                    'phone' => null,
                ])->save();

                return [
                    'order' => $order->fresh(),
                    'guest_session' => $this->issueGuestOrderSession((int) $order->customer_id, (int) $order->business_id),
                ];
            });
        } catch (InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        } catch (ValidationException $exception) {
            return $this->sendError('One or more menu items can no longer be ordered.', $exception->errors(), HTTP_UNPROCESSABLE_ENTITY);
        }

        if (isset($result['error'])) {
            return $this->sendError($result['error'], [], $result['status']);
        }

        return $this->sendResponse(
            ['order' => $result['order'], 'guest_session' => $result['guest_session']],
            'Phone verified. Your order has been sent to the business and is waiting for approval.',
            HTTP_OK
        );
    }

    /**
     * List a verified guest's active orders for only the currently scanned business.
     */
    public function activeGuestOrders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_uuid' => ['required', 'uuid'],
            'access_token' => ['required', 'string', 'max:160'],
        ]);
        if ($validator->fails()) {
            return $this->sendError('Valid business and verified session are required.', $validator->errors(), HTTP_UNPROCESSABLE_ENTITY);
        }

        [$sessionUuid, $secret] = array_pad(explode('.', $request->input('access_token'), 2), 2, null);
        $session = $secret
            ? OrderCustomerSession::query()->where('uuid', $sessionUuid)->where('expires_at', '>', now())->first()
            : null;
        if (!$session || !Hash::check($secret, $session->token_hash)) {
            return $this->sendError('Your order session has expired. Verify your phone again to view active orders.', [], HTTP_UNAUTHORIZED);
        }

        $business = Business::query()->where('uuid', $request->input('business_uuid'))->first();
        if (!$business) {
            return $this->sendError('Business not found.', [], HTTP_NOT_FOUND);
        }
        if ((int) $session->business_id !== (int) $business->id) {
            return $this->sendError('This verified order session belongs to a different business.', [], HTTP_FORBIDDEN);
        }

        $statuses = OrderStatus::query()->get(['id', 'name']);
        $statusNameById = $statuses->mapWithKeys(fn ($status) => [(string) $status->id => $status->name]);
        $activeStatusIds = $statuses
            ->whereIn('name', ['Pending', 'Processing'])
            ->map(fn ($status) => (string) $status->id)
            ->values()
            ->all();
        $terminalStatusIds = $statuses
            ->whereIn('name', ['Cancelled', 'Refunded'])
            ->map(fn ($status) => (string) $status->id)
            ->values()
            ->all();
        $paymentStatuses = PaymentStatus::query()->get(['id', 'name']);
        $paymentStatusNameById = $paymentStatuses->mapWithKeys(fn ($status) => [(string) $status->id => $status->name]);
        $pendingPaymentStatusId = $paymentStatuses->firstWhere('name', 'Pending')?->id;

        $orders = Order::query()
            ->with(['items.item'])
            ->where('business_id', $business->id)
            ->where('customer_id', $session->customer_id)
            ->where(function ($query) use ($activeStatusIds, $terminalStatusIds, $pendingPaymentStatusId) {
                $query->whereIn('order_status_id', $activeStatusIds);
                if ($pendingPaymentStatusId !== null) {
                    $query->orWhere(function ($awaitingPayment) use ($pendingPaymentStatusId, $terminalStatusIds) {
                        $awaitingPayment->where('payment_status_id', $pendingPaymentStatusId);
                        if ($terminalStatusIds) {
                            $awaitingPayment->whereNotIn('order_status_id', $terminalStatusIds);
                        }
                    });
                }
            })
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Order $order) => [
                'uuid' => $order->uuid,
                'number' => $order->number,
                'status' => $statusNameById[(string) $order->order_status_id] ?? 'Pending',
                'payment_status' => $paymentStatusNameById[(string) $order->payment_status_id] ?? 'Pending',
                'total_amount' => $order->total_amount,
                'currency' => $business->currency ?? 'TZS',
                'created_at' => $order->created_at,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->item?->name ?? 'Menu item',
                    'quantity' => $item->quantity,
                ])->values(),
            ])->values();

        return $this->sendResponse(['orders' => $orders], 'Active orders retrieved successfully.', HTTP_OK);
    }

    private function issueGuestOrderSession(int $customerId, int $businessId): array
    {
        $secret = Str::random(48);
        $session = OrderCustomerSession::query()->create([
            'business_id' => $businessId,
            'customer_id' => $customerId,
            'token_hash' => Hash::make($secret),
            'expires_at' => now()->addDays(7),
        ]);

        return [
            'access_token' => $session->uuid . '.' . $secret,
            'expires_at' => $session->expires_at,
        ];
    }

    public function resendCheckoutVerification(string $uuid)
    {
        $checkout = OrderCheckoutVerification::query()->where('uuid', $uuid)->first();
        if (!$checkout) {
            return $this->sendError('Checkout verification was not found.', [], HTTP_NOT_FOUND);
        }
        if ($checkout->order_id || $checkout->verified_at) {
            return $this->sendError('This checkout has already been verified.', [], HTTP_UNPROCESSABLE_ENTITY);
        }
        $sessionExpiresAt = $checkout->created_at->copy()->addMinutes(self::CHECKOUT_SESSION_MINUTES);
        if (now()->gte($sessionExpiresAt)) {
            return $this->sendError('This checkout has expired. Please start checkout again.', [], 410);
        }
        $resendAvailableAt = ($checkout->code_sent_at ?? $checkout->created_at)->copy()->addSeconds(20);
        if (now()->lt($resendAvailableAt)) {
            $seconds = max(1, $resendAvailableAt->getTimestamp() - now()->getTimestamp());
            return $this->sendError("Please wait {$seconds} seconds before requesting another code.", [], 429);
        }
        if ($checkout->resend_count >= 3) {
            return $this->sendError('You have reached the resend limit. Please start checkout again later.', [], 429);
        }

        $otp = (string) random_int(1000, 9999);
        $codeExpiresAt = now()->addMinutes(self::CHECKOUT_CODE_MINUTES);
        if ($codeExpiresAt->gt($sessionExpiresAt)) {
            $codeExpiresAt = $sessionExpiresAt;
        }
        $checkout->update([
            'verification_code' => Hash::make($otp),
            'code_sent_at' => now(),
            'attempts' => 0,
            'resend_count' => $checkout->resend_count + 1,
            'expires_at' => $codeExpiresAt,
        ]);
        Log::info('Order checkout verification code resent (local testing only)', [
            'checkout_uuid' => $checkout->uuid,
            'otp' => $otp,
        ]);

        return $this->sendResponse([
            'resend_available_at' => now()->addSeconds(20),
        ], 'Verification code generated. Check the Laravel log during local testing.', HTTP_OK);
    }

    public function resumeCheckoutVerification(string $uuid)
    {
        $checkout = OrderCheckoutVerification::query()->where('uuid', $uuid)->first();
        if (!$checkout) {
            return $this->sendError('Checkout verification was not found. Please start checkout again.', [], HTTP_NOT_FOUND);
        }
        $code = Code::query()->with('codable.business')->find($checkout->code_id);
        $businessUuid = $code?->codable?->business?->uuid;
        if (!$businessUuid) {
            return $this->sendError('The business for this checkout could not be found. Please scan its QR code again.', [], HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($checkout->order_id) {
            $order = Order::query()->find($checkout->order_id);
            if ($order) {
                return $this->sendResponse([
                    'verification' => [
                        'completed' => true,
                        'order_number' => $order->number,
                        'business_uuid' => $businessUuid,
                    ],
                    'guest_session' => $this->issueGuestOrderSession((int) $order->customer_id, (int) $order->business_id),
                ], 'This checkout was already completed.', HTTP_OK);
            }
        }
        if ($checkout->verified_at) {
            return $this->sendError('This checkout has already been verified.', [], 409);
        }
        if ($checkout->created_at->copy()->addMinutes(self::CHECKOUT_SESSION_MINUTES)->isPast()) {
            return $this->sendError('This checkout has expired. Please start checkout again.', [], 410);
        }

        $digits = preg_replace('/\\D+/', '', (string) $checkout->phone);
        $maskedPhone = strlen($digits) > 6
            ? '+' . substr($digits, 0, 3) . str_repeat('•', strlen($digits) - 6) . substr($digits, -3)
            : '+' . str_repeat('•', max(0, strlen($digits) - 2)) . substr($digits, -2);

        return $this->sendResponse([
            'verification' => [
                'business_uuid' => $businessUuid,
                'masked_phone' => $maskedPhone,
                'expires_at' => $checkout->expires_at,
                'session_expires_at' => $checkout->created_at->copy()->addMinutes(self::CHECKOUT_SESSION_MINUTES),
                'code_expired' => $checkout->expires_at->isPast(),
                'resend_available_at' => ($checkout->code_sent_at ?? $checkout->created_at)->copy()->addSeconds(20),
            ],
        ], 'Pending phone verification restored.', HTTP_OK);
    }

    private function checkoutUnavailableReason(Code $code, string $channel): ?string
    {
        if (!in_array($channel, OrderingChannel::activeSlugs(), true)) {
            return 'This ordering channel is no longer available.';
        }
        if (!$code->is_active) {
            return 'This menu QR code is disabled. Please contact the business.';
        }

        $business = $code->codable?->business;
        if (!$business || !$business->is_active) {
            return 'This business is currently unavailable.';
        }
        if (!$this->channelEnabled($business, $channel)) {
            return 'This ordering channel is not enabled for this business.';
        }
        if ($code->codable instanceof \App\Models\Section\ServicePoint && !$code->codable->is_active) {
            return 'This table, room, or service point is not currently accepting orders.';
        }

        $businessStatus = app(MenuAvailabilityService::class)->businessStatus($business);
        if (!$businessStatus['is_open_now']) {
            return $businessStatus['reason'];
        }

        return null;
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
            'tax',
            'items.options',
            'items.item',
            'servicePoint.section',
            'servicePoint.subSection',
            'orderingChannel',
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
