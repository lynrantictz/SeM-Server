<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Api\BaseController;
use App\Models\Order\Order;
use App\Models\Payment\MobileMoneyProvider;
use App\Models\Payment\Payment;
use App\Jobs\WhatsApp\SendOrderPaymentRequestWhatsApp;
use App\Services\Business\BusinessActivationService;
use App\Services\Order\GuestOrderSessionService;
use App\Services\Order\GuestCustomerSessionService;
use App\Services\Order\OrderPaymentLinkService;
use App\Services\PaymentGateway\PaymentCheckoutService;
use App\Services\PaymentGateway\PaymentSettlementService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Http\Request;

class PaymentGatewayController extends BaseController
{
    public function __construct(
        private readonly PaymentCheckoutService $checkout,
        private readonly GuestOrderSessionService $guestSessions,
        private readonly GuestCustomerSessionService $guestCustomerSessions,
        private readonly OrderPaymentLinkService $paymentLinks,
        private readonly PaymentSettlementService $settlements,
        private readonly BusinessActivationService $activation,
    )
    {
    }

    public function providers(Request $request, string $order)
    {
        $record = Order::query()
            ->with('business.district.city')
            ->where('number', $order)
            ->firstOrFail();
        if (! $this->canAccessOrder($request, $record, $request->query('access_token'))) {
            return $this->sendError('Verify your phone to access payment options for this order.', [], HTTP_UNAUTHORIZED);
        }
        if (! $this->activation->status($record->business)['can_accept_mobile_money']) {
            return $this->sendError('Mobile-money checkout is not available for this business yet.', [], HTTP_UNPROCESSABLE_ENTITY);
        }
        $countryId = $record->business?->district?->city?->country_id;

        return $this->sendResponse([
            'is_sandbox' => config('payments.azampay.environment') === 'sandbox',
            'providers' => MobileMoneyProvider::query()
                ->where('gateway', 'azampay')
                ->where('is_active', true)
                ->when($countryId, fn ($query) => $query->where(fn ($countryQuery) => $countryQuery
                    ->where('country_id', $countryId)
                    ->orWhereNull('country_id')))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (MobileMoneyProvider $provider) => [
                    'code' => $provider->code,
                    'name' => $provider->name,
                    'logo_url' => $provider->logo_url,
                ]),
        ], 'Mobile-money providers retrieved successfully.');
    }

    public function paymentLink(string $token)
    {
        $resolution = $this->paymentLinks->resolveWithStatus($token);
        $link = $resolution['link'];
        if (! $link) {
            $code = match ($resolution['status']) {
                'expired' => 'PAYMENT_LINK_EXPIRED',
                'revoked' => 'PAYMENT_LINK_REVOKED',
                default => 'PAYMENT_LINK_INVALID',
            };
            return $this->sendError('This payment link is no longer available. It may have expired or been replaced.', ['code' => $code], HTTP_GONE);
        }

        $order = Order::query()->with([
            'status',
            'paymentStatus',
            'business.contacts',
            'business.district.city.country',
            'items.item',
            'orderingChannel',
            'tax',
        ])->find($link->order_id);
        if (! $order) {
            return $this->sendError('This payment link is no longer available.', ['code' => 'PAYMENT_LINK_INVALID'], HTTP_GONE);
        }
        if ($order->paymentStatus?->name === 'Completed') {
            return $this->sendError('This order has already been paid. Thank you.', ['code' => 'ORDER_ALREADY_PAID'], HTTP_GONE);
        }
        if (in_array($order->status?->name, ['Cancelled', 'Refunded', 'Completed'], true)) {
            return $this->sendError('This order is no longer available for payment.', ['code' => 'ORDER_NOT_PAYABLE'], HTTP_GONE);
        }

        $minutes = max(1, now()->diffInMinutes($link->expires_at, false));
        $session = $this->guestSessions->issueForOrder($order, $minutes);
        return $this->sendResponse([
            'order_number' => $order->number,
            'payment_session' => $session['access_token'],
            'expires_at' => $link->expires_at,
            'business_name' => $order->business?->name,
            'business_logo_url' => $order->business?->logo_url,
            'business_location' => $order->business?->location,
            'business_city' => $order->business?->district?->city?->name,
            'business_country' => $order->business?->district?->city?->country?->name,
            'business_contacts' => $order->business?->contacts->pluck('contact')->values()->all() ?? [],
            'order_type' => $order->orderingChannel?->name ?? $order->channel,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->item?->name ?? 'Menu item',
                'quantity' => $item->quantity,
                'total_amount' => $item->total_amount,
            ])->values()->all(),
            'subtotal_amount' => $order->total_items_amount,
            'tax_amount' => $order->tax_amount,
            'tax_percent' => $order->tax?->percent,
            'total_amount' => $order->total_amount,
            'currency' => $order->business?->currency ?? 'TZS',
        ], 'Payment link verified.');
    }

    public function createSharePaymentLink(Request $request, string $order)
    {
        $validated = $request->validate([
            'access_token' => ['nullable', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:30'],
        ]);
        $record = Order::query()
            ->with(['status', 'paymentStatus', 'customer', 'business.district.city.country'])
            ->where('number', $order)
            ->firstOrFail();
        if (! $this->canAccessOrder($request, $record, $request->input('access_token'))) {
            return $this->sendError('Verify your phone to share this payment link.', [], HTTP_UNAUTHORIZED);
        }
        if (! in_array($record->status?->name, ['Processing', 'Served'], true) || $record->paymentStatus?->name === 'Completed') {
            return $this->sendError('This order is not available for payment sharing.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $recipient = (new PhoneNumberNormalizer())->normalize(
                $validated['phone'],
                $record->business?->district?->city?->country?->iso2,
            );
        } catch (\App\Exceptions\InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), ['phone' => [$exception->getMessage()]], HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($recipient === $record->customer?->phone_e164) {
            return $this->sendError('Please enter a different mobile number. This payment link is for someone else to pay.', ['phone' => ['Enter a number different from the customer on this order.']], HTTP_UNPROCESSABLE_ENTITY);
        }
        $limit = max(1, (int) config('payments.customer_payment_link_send_limit', 2));
        $used = $this->paymentLinks->customerShareCount($record);
        if ($used >= $limit) {
            return $this->sendError('This order has reached its payment-link sharing limit.', ['code' => 'PAYMENT_LINK_SHARE_LIMIT_REACHED'], HTTP_TOO_MANY_REQUESTS);
        }

        $issued = $this->paymentLinks->issue($record, null, $recipient, 'customer', false);
        \Illuminate\Support\Facades\DB::afterCommit(fn () => SendOrderPaymentRequestWhatsApp::dispatch($issued['link']->uuid, $issued['token'])->onQueue(config('whatsapp.queue')));
        $used++;
        return $this->sendResponse([
            'expires_at' => $issued['link']->expires_at,
            'send_limit' => $limit,
            'remaining_sends' => max(0, $limit - $used),
            'share_activity' => $this->paymentLinks->customerShareActivity($record),
        ], 'Secure payment link queued for WhatsApp delivery.');
    }

    public function sharePaymentLinkActivity(Request $request, string $order)
    {
        $record = Order::query()->with(['status', 'paymentStatus'])->where('number', $order)->firstOrFail();
        if (! $this->canAccessOrder($request, $record, $request->query('access_token'))) {
            return $this->sendError('Verify your phone to view payment-link activity for this order.', [], HTTP_UNAUTHORIZED);
        }

        $limit = max(1, (int) config('payments.customer_payment_link_send_limit', 2));
        $used = $this->paymentLinks->customerShareCount($record);

        return $this->sendResponse([
            'send_limit' => $limit,
            'remaining_sends' => max(0, $limit - $used),
            'share_activity' => $this->paymentLinks->customerShareActivity($record),
        ], 'Payment-link activity retrieved.');
    }

    public function checkout(Request $request, string $order)
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^[1-9][0-9]{6,14}$/'],
            'provider' => ['required', 'string', 'max:40'],
            'access_token' => ['nullable', 'string', 'max:160'],
        ]);

        $record = Order::query()
            ->with(['business.district.city.country', 'status', 'paymentStatus'])
            ->where('number', $order)
            ->firstOrFail();
        if (! $this->canAccessOrder($request, $record, $validated['access_token'] ?? null)) {
            return $this->sendError('Verify your phone to request payment for this order.', [], HTTP_UNAUTHORIZED);
        }
        if (! in_array($record->status?->name, ['Processing', 'Served'], true)) {
            return $this->sendError('Payment is available after the business accepts your order.', [], HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($record->paymentStatus?->name === 'Completed') {
            return $this->sendError('This order has already been paid.', [], 409);
        }
        if (! $this->activation->status($record->business)['can_accept_mobile_money']) {
            return $this->sendError('Mobile-money checkout is not available for this business yet.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $country = $record->business?->district?->city?->country;
        $providerExists = MobileMoneyProvider::query()
            ->where('gateway', 'azampay')
            ->where('is_active', true)
            ->where('code', $validated['provider'])
            ->where(fn ($query) => $query->where('country_id', $country?->id)->orWhereNull('country_id'))
            ->exists();
        if (! $providerExists) {
            return $this->sendError('The selected mobile-money provider is not available for this business.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $phone = (new PhoneNumberNormalizer())->normalize($validated['phone'], $country?->iso2);
        } catch (\App\Exceptions\InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }
        $checkout = $this->checkout->initiateMnoCheckout(
            $record,
            $phone,
            $validated['provider'],
            auth()->id(),
            auth()->check() ? 'staff' : 'customer',
        );

        return $this->sendResponse([
            'payment' => [
                ...$this->paymentData($checkout->payment),
                'prompt_sent' => $checkout->promptSent,
                'awaiting_gateway_confirmation' => $checkout->awaitingGatewayConfirmation,
                'queued' => $checkout->queued,
            ],
        ], 'Mobile money prompt initiated.');
    }

    public function status(Request $request, string $order)
    {
        $record = Order::query()->where('number', $order)->firstOrFail();
        if (! $this->canAccessOrder($request, $record, $request->query('access_token'))) {
            return $this->sendError('Verify your phone to view payment status for this order.', [], HTTP_UNAUTHORIZED);
        }
        $payment = Payment::query()->where('order_id', $record->id)->latest('id')->first();

        return $this->sendResponse(['payment' => $payment ? $this->paymentData($payment) : null], 'Payment status retrieved.');
    }

    public function completeSandboxDemo(Request $request, string $order)
    {
        if (config('payments.azampay.environment') !== 'sandbox') {
            return $this->sendError('Sandbox payment completion is not available in the live environment.', [], HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'access_token' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'regex:/^[1-9][0-9]{6,14}$/'],
            'provider' => ['nullable', 'string', 'max:40'],
        ]);
        $record = Order::query()->where('number', $order)->firstOrFail();
        if (! $this->canAccessOrder($request, $record, $validated['access_token'] ?? null)) {
            return $this->sendError('Verify your phone to complete this sandbox payment.', [], HTTP_UNAUTHORIZED);
        }

        $payment = Payment::query()
            ->where('order_id', $record->id)
            ->whereIn('status', ['PENDING', 'PROCESSING'])
            ->latest('id')
            ->first();

        try {
            if ($payment) {
                $completed = $this->settlements->completeSandboxDemo($payment);
            } else {
                if (empty($validated['phone']) || empty($validated['provider'])) {
                    return $this->sendError('Choose a mobile-money provider and number before completing the sandbox demo.', [], HTTP_UNPROCESSABLE_ENTITY);
                }

                $country = $record->business?->district?->city?->country;
                $providerExists = MobileMoneyProvider::query()
                    ->where('gateway', 'azampay')
                    ->where('is_active', true)
                    ->where('code', $validated['provider'])
                    ->where(fn ($query) => $query->where('country_id', $country?->id)->orWhereNull('country_id'))
                    ->exists();
                if (! $providerExists) {
                    return $this->sendError('The selected mobile-money provider is not available for this business.', [], HTTP_UNPROCESSABLE_ENTITY);
                }

                $phone = (new PhoneNumberNormalizer())->normalize($validated['phone'], $country?->iso2);
                $completed = $this->settlements->completeSandboxDemoForOrder(
                    $record,
                    $phone,
                    $validated['provider'],
                    auth()->id(),
                    auth()->check() ? 'staff' : 'customer',
                );
            }
        } catch (\RuntimeException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        } catch (\App\Exceptions\InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->sendResponse([
            'payment' => $this->paymentData($completed),
        ], 'Sandbox payment completed. No real money was collected.');
    }

    private function paymentData(Payment $payment): array
    {
        return [
            'uuid' => $payment->uuid,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'failure_reason' => $payment->failure_reason,
        ];
    }

    private function canAccessOrder(Request $request, Order $order, ?string $legacyToken): bool
    {
        $persistentSession = $this->guestCustomerSessions->resolveFromRequest($request);
        if ($persistentSession && (int) $persistentSession->customer_id === (int) $order->customer_id) {
            return true;
        }

        return (bool) $this->guestSessions->resolveForOrder($order, $legacyToken);
    }
}
