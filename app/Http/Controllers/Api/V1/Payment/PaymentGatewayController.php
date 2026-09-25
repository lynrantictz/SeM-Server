<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Api\BaseController;
use App\Models\Order\Order;
use App\Models\Payment\MobileMoneyProvider;
use App\Models\Payment\Payment;
use App\Services\Order\GuestOrderSessionService;
use App\Services\PaymentGateway\PaymentCheckoutService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Http\Request;

class PaymentGatewayController extends BaseController
{
    public function __construct(
        private readonly PaymentCheckoutService $checkout,
        private readonly GuestOrderSessionService $guestSessions,
    )
    {
    }

    public function providers(Request $request, string $order)
    {
        $record = Order::query()
            ->with('business.district.city')
            ->where('number', $order)
            ->firstOrFail();
        if (! $this->guestSessions->resolveForOrder($record, $request->query('access_token'))) {
            return $this->sendError('Verify your phone to access payment options for this order.', [], HTTP_UNAUTHORIZED);
        }
        $countryId = $record->business?->district?->city?->country_id;

        return $this->sendResponse([
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

    public function checkout(Request $request, string $order)
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^[1-9][0-9]{6,14}$/'],
            'provider' => ['required', 'string', 'max:40'],
            'access_token' => ['required', 'string', 'max:160'],
        ]);

        $record = Order::query()
            ->with(['business.district.city.country', 'status', 'paymentStatus'])
            ->where('number', $order)
            ->firstOrFail();
        if (! $this->guestSessions->resolveForOrder($record, $validated['access_token'])) {
            return $this->sendError('Verify your phone to request payment for this order.', [], HTTP_UNAUTHORIZED);
        }
        if (! in_array($record->status?->name, ['Processing', 'Served'], true)) {
            return $this->sendError('Payment is available after the business accepts your order.', [], HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($record->paymentStatus?->name === 'Completed') {
            return $this->sendError('This order has already been paid.', [], 409);
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
        if (! $this->guestSessions->resolveForOrder($record, $request->query('access_token'))) {
            return $this->sendError('Verify your phone to view payment status for this order.', [], HTTP_UNAUTHORIZED);
        }
        $payment = Payment::query()->where('order_id', $record->id)->latest('id')->first();

        return $this->sendResponse(['payment' => $payment ? $this->paymentData($payment) : null], 'Payment status retrieved.');
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
}
