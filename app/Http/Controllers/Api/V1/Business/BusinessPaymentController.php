<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessUser;
use App\Models\Business\BusinessPayoutAccount;
use App\Models\Order\Order;
use App\Models\Payment\MobileMoneyProvider;
use App\Services\Business\BusinessActivationService;
use App\Services\PaymentGateway\PaymentCheckoutService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class BusinessPaymentController extends BaseController
{
    public function __construct(
        private readonly PaymentCheckoutService $checkout,
        private readonly BusinessActivationService $activation,
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

    public function payoutSettings(Business $business)
    {
        $this->authorizePayoutSettings($business);
        $business->loadMissing(['district.city.country', 'paymentSetting']);

        $accounts = $business->payoutAccounts()
            ->with('country:id,name,iso2,phone_code')
            ->latest('is_default')
            ->latest('id')
            ->get();
        $activation = $this->activation->status($business);
        $default = $accounts->first(fn (BusinessPayoutAccount $account) =>
            $account->is_default && $account->status === 'active' && $account->verification_status === 'verified'
        );
        $settlementEnabled = (bool) $business->paymentSetting?->is_settlement_enabled;

        return $this->sendResponse([
            'business' => [
                'uuid' => $business->uuid,
                'name' => $business->name,
                'country' => $business->district?->city?->country?->name,
                'currency' => $business->paymentSetting?->currency ?: 'TZS',
            ],
            'payment_setting' => $business->paymentSetting,
            'payout_accounts' => $accounts->map(fn (BusinessPayoutAccount $account) => $this->payoutAccountData($account))->values(),
            'payout_readiness' => [
                'business_active' => $activation['business_enabled'],
                'documents_approved' => $activation['documents_approved'],
                'checkout_enabled' => $activation['payment_checkout_enabled'],
                'settlement_enabled' => $settlementEnabled,
                'verified_default_account' => (bool) $default,
                'ready_for_payout_review' => $activation['business_enabled'] && $activation['documents_approved'] && $activation['payment_checkout_enabled'],
                'ready_for_automatic_payout' => $activation['business_enabled'] && $activation['documents_approved'] && $activation['payment_checkout_enabled'] && $settlementEnabled && (bool) $default,
            ],
        ], 'Payout settings retrieved successfully.');
    }

    public function storePayoutAccount(Request $request, Business $business)
    {
        $this->authorizePayoutSettings($business);
        $validated = $request->validate([
            'destination_type' => ['required', 'in:mobile_money'],
            'provider' => ['required', 'string', 'max:80'],
            'account_number' => ['required', 'regex:/^[1-9][0-9]{6,14}$/'],
            'account_holder_name' => ['required', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $business->loadMissing('district.city.country');
        $country = $business->district?->city?->country;
        $provider = MobileMoneyProvider::query()
            ->where('gateway', 'azampay')
            ->where('code', $validated['provider'])
            ->where('is_active', true)
            ->when($country?->id, fn ($query) => $query->where(fn ($countryQuery) => $countryQuery
                ->where('country_id', $country->id)->orWhereNull('country_id')))
            ->first();
        abort_unless($provider, HTTP_UNPROCESSABLE_ENTITY, 'Select an active mobile-money provider for this country.');

        try {
            $accountNumber = app(PhoneNumberNormalizer::class)->normalize($validated['account_number'], $country?->iso2);
        } catch (\App\Exceptions\InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $account = $business->payoutAccounts()->create([
            'gateway' => 'azampay',
            'destination_type' => 'mobile_money',
            'provider' => $provider->code,
            'account_number' => $accountNumber,
            'account_holder_name' => trim($validated['account_holder_name']),
            'currency' => strtoupper($validated['currency'] ?? $business->paymentSetting?->currency ?? 'TZS'),
            'country_id' => $country?->id,
            'verification_status' => 'pending',
            'status' => 'pending',
            'is_default' => false,
        ]);

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($account)], 'Payout account submitted for Paperstic verification.', 201);
    }

    public function updatePayoutAccount(Request $request, Business $business, BusinessPayoutAccount $payoutAccount)
    {
        $this->authorizePayoutSettings($business);
        abort_unless((int) $payoutAccount->business_id === (int) $business->id, HTTP_NOT_FOUND, 'Payout account not found.');

        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:80'],
            'account_number' => ['required', 'regex:/^[1-9][0-9]{6,14}$/'],
            'account_holder_name' => ['required', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $business->loadMissing('district.city.country');
        $country = $business->district?->city?->country;
        $provider = MobileMoneyProvider::query()->where('gateway', 'azampay')->where('code', $validated['provider'])->where('is_active', true)->first();
        abort_unless($provider, HTTP_UNPROCESSABLE_ENTITY, 'Select an active mobile-money provider.');
        try {
            $accountNumber = app(PhoneNumberNormalizer::class)->normalize($validated['account_number'], $country?->iso2);
        } catch (\App\Exceptions\InvalidPhoneNumberException $exception) {
            return $this->sendError($exception->getMessage(), [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $payoutAccount->update([
            'provider' => $provider->code,
            'account_number' => $accountNumber,
            'account_holder_name' => trim($validated['account_holder_name']),
            'currency' => strtoupper($validated['currency'] ?? $business->paymentSetting?->currency ?? 'TZS'),
            'verification_status' => 'pending',
            'status' => 'pending',
            'is_default' => false,
            'verified_by' => null,
            'verified_at' => null,
            'rejection_reason' => null,
        ]);

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($payoutAccount->fresh())], 'Payout account updated and returned for verification.');
    }

    public function makeDefaultPayoutAccount(Business $business, BusinessPayoutAccount $payoutAccount)
    {
        $this->authorizePayoutSettings($business);
        abort_unless((int) $payoutAccount->business_id === (int) $business->id, HTTP_NOT_FOUND, 'Payout account not found.');
        abort_unless($payoutAccount->status === 'active' && $payoutAccount->verification_status === 'verified', HTTP_UNPROCESSABLE_ENTITY, 'Only a verified active payout account can be made default.');

        DB::transaction(function () use ($business, $payoutAccount) {
            $business->payoutAccounts()->update(['is_default' => false]);
            $payoutAccount->update(['is_default' => true]);
        });

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($payoutAccount->fresh())], 'Default payout account updated.');
    }

    public function uploadOnboardingProof(Request $request, Business $business)
    {
        $this->authorizePayoutSettings($business);
        $payment = $business->onboardingPayment()->firstOrFail();
        $validated = $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:10240']]);
        if ($payment->proof_path) {
            Storage::disk('local')->delete($payment->proof_path);
        }
        $file = $validated['file'];
        $payment->update([
            'proof_path' => $file->store("onboarding-payments/{$business->uuid}", 'local'),
            'proof_filename' => $file->getClientOriginalName(),
            'uploaded_by' => $request->user()->id,
            'status' => $payment->status === 'paid' ? 'paid' : 'submitted',
        ]);

        return $this->sendResponse(['proof_filename' => $payment->proof_filename], 'Onboarding payment proof uploaded.');
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
                'queued' => $checkout->queued,
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
        $status = $this->activation->status($business);

        if (! $status['business_enabled']) {
            abort(HTTP_UNPROCESSABLE_ENTITY, 'This business is not active yet.');
        }

        if (! $status['documents_approved']) {
            abort(HTTP_UNPROCESSABLE_ENTITY, 'Required compliance documents must be approved before mobile-money checkout can be enabled.');
        }

        abort_unless($status['payment_checkout_enabled'], HTTP_UNPROCESSABLE_ENTITY,
            'Mobile-money checkout is not enabled for this business yet.');
    }

    private function authorizePayoutSettings(Business $business): void
    {
        $role = $this->authorizePaymentAccess($business, false);
        abort_unless(in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager'], true), HTTP_FORBIDDEN, 'Only business management can manage payout information.');
    }

    private function payoutAccountData(BusinessPayoutAccount $account): array
    {
        return [
            'uuid' => $account->uuid,
            'destination_type' => $account->destination_type,
            'provider' => $account->provider,
            'account_number' => $account->maskedAccountNumber(),
            'account_holder_name' => $account->account_holder_name,
            'currency' => $account->currency,
            'verification_status' => $account->verification_status,
            'status' => $account->status,
            'is_default' => (bool) $account->is_default,
            'verified_at' => $account->verified_at?->toIso8601String(),
            'rejection_reason' => $account->rejection_reason,
        ];
    }
}
