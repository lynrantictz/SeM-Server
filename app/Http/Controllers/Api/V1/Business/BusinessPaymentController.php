<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessUser;
use App\Models\Order\Order;
use App\Models\Payment\MobileMoneyProvider;
use App\Services\PaymentGateway\PaymentCheckoutService;
use Illuminate\Http\Request;

class BusinessPaymentController extends BaseController
{
    public function __construct(
        private readonly PaymentCheckoutService $checkout,
    ) {
    }

    public function providers(Business $business)
    {
        $this->authorizePaymentAccess($business, false);
        $this->ensureCheckoutEnabled($business);

        $countryId = $business->district?->city?->country_id;

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
                    'id' => $provider->code,
                    'name' => $provider->name,
                    'logo_url' => $provider->logo_url,
                ]),
        ], 'Payment providers retrieved successfully.');
    }

    public function checkout(Request $request, Business $business, string $order)
    {
        $role = $this->authorizePaymentAccess($business, true);
        $this->ensureCheckoutEnabled($business);
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^[1-9][0-9]{6,14}$/'],
            'provider' => ['required', 'string', 'max:100'],
        ]);

        $record = Order::query()
            ->where('business_id', $business->id)
            ->where('uuid', $order)
            ->firstOrFail();
        abort_unless($record->status?->name === 'Served', HTTP_UNPROCESSABLE_ENTITY, 'Only served orders can be sent for payment.');
        abort_unless($record->paymentStatus?->name !== 'Completed', HTTP_UNPROCESSABLE_ENTITY, 'This order is already paid.');
        abort_unless($role !== 'waiter' || $record->channel === 'dine_in', HTTP_FORBIDDEN, 'Waiters can request payment only for dine-in orders.');

        $countryId = $business->district?->city?->country_id;
        $provider = MobileMoneyProvider::query()
            ->where('gateway', 'azampay')
            ->where('code', $validated['provider'])
            ->where('is_active', true)
            ->when($countryId, fn ($query) => $query->where(fn ($countryQuery) => $countryQuery
                ->where('country_id', $countryId)
                ->orWhereNull('country_id')))
            ->first();
        abort_unless($provider, HTTP_UNPROCESSABLE_ENTITY, 'Select an active mobile-money provider.');

        $checkout = $this->checkout->initiateMnoCheckout(
            $record,
            $validated['phone'],
            $provider->code,
            auth()->id(),
            'staff',
        );

        return $this->sendResponse([
            'payment' => [
                'uuid' => $checkout->payment->uuid,
                'status' => $checkout->payment->status,
                'amount' => $checkout->payment->amount,
                'currency' => $checkout->payment->currency,
                'expires_at' => $checkout->payment->expires_at?->toIso8601String(),
                'prompt_sent' => $checkout->promptSent,
                'awaiting_gateway_confirmation' => $checkout->awaitingGatewayConfirmation,
            ],
        ], 'Mobile-money prompt initiated.');
    }

    private function authorizePaymentAccess(Business $business, bool $canInitiate): string
    {
        $user = auth()->user();
        $staff = BusinessUser::query()->where('business_id', $business->id)->where('user_id', $user->id)->where('is_active', true)->first();
        if ($user->type === 'business') {
            abort_unless($staff && $user->is_active, HTTP_FORBIDDEN, 'You do not have access to this business.');
            $role = $staff->business_role;
        } else {
            $vendor = $user->vendors()->whereKey($business->vendor_id)->first();
            abort_unless($vendor && ($vendor->pivot->is_primary || $vendor->pivot->is_active), HTTP_FORBIDDEN, 'You do not have access to this business.');
            $role = $vendor->pivot->is_primary ? 'owner' : 'vendor_manager';
        }

        if ($canInitiate) {
            abort_unless(in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager', 'counter', 'counter-clerk', 'waiter'], true), HTTP_FORBIDDEN, 'Your role cannot request payment.');
        }

        return $role;
    }

    private function ensureCheckoutEnabled(Business $business): void
    {
        $setting = $business->paymentSetting;
        abort_unless($setting?->provider === 'azampay' && $setting->is_checkout_enabled, HTTP_UNPROCESSABLE_ENTITY,
            'Mobile-money checkout is not enabled for this business yet.');
    }
}
