<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessUser;
use App\Models\Business\BusinessPayoutAccount;
use App\Models\Business\BusinessPaymentMethod;
use App\Models\Business\BusinessPaymentMethodAccount;
use App\Models\Order\Order;
use App\Models\Payment\MobileMoneyProvider;
use App\Models\Payment\PaymentMethod;
use App\Models\SystemSetting;
use App\Services\Business\BusinessActivationService;
use App\Services\PaymentGateway\PaymentCheckoutService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function paymentMethods(Business $business)
    {
        $this->authorizePaymentAccess($business, false);
        $business->loadMissing('district.city.country', 'paymentSetting');

        $countryId = $business->district?->city?->country_id;
        $catalog = PaymentMethod::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('country_id', $countryId)->orWhereNull('country_id'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $configured = $business->paymentMethods()->with(['paymentMethod', 'accounts'])->get()->keyBy('payment_method_id');
        $activation = $this->activation->status($business);
        $configuredMethods = $configured->filter(fn (BusinessPaymentMethod $configuration) =>
            $configuration->is_enabled && $configuration->status === 'active'
        );
        $completeMethods = $configuredMethods->filter(function (BusinessPaymentMethod $configuration) {
            $method = $configuration->paymentMethod;

            if (! $method) {
                return false;
            }

            if ($method->code === 'bank_transfer') {
                return $configuration->accounts->isNotEmpty()
                    && $configuration->accounts->every(fn (BusinessPaymentMethodAccount $account) =>
                        $account->is_enabled && filled($account->bank_name) && filled($account->account_number) && filled($account->account_holder_name)
                    );
            }

            return ! $method->requires_identifier || filled($configuration->identifier);
        });
        $manualPaymentReady = $activation['manual_payment_ready']
            && $configuredMethods->isNotEmpty()
            && $completeMethods->count() === $configuredMethods->count();

        return $this->sendResponse([
            'payment_timing' => $business->paymentSetting?->payment_timing ?? 'after_approval',
            'methods' => $catalog->map(fn (PaymentMethod $method) => $this->paymentMethodData($method, $configured->get($method->id)))->values(),
            'readiness' => [
                'business_active' => $activation['business_enabled'],
                'documents_approved' => $activation['documents_approved'],
                'methods_configured' => $configuredMethods->count(),
                'methods_complete' => $completeMethods->count(),
                'ready_for_manual_payments' => $manualPaymentReady,
                'online_checkout_available' => $activation['online_checkout_ready'],
            ],
        ], 'Business payment methods retrieved successfully.');
    }

    public function savePaymentMethods(Request $request, Business $business)
    {
        $this->authorizePaymentSettings($business);
        $validated = $request->validate([
            'payment_timing' => ['nullable', Rule::in(['after_approval', 'after_served', 'anytime'])],
            'methods' => ['present', 'array', 'max:10'],
            'methods.*.payment_method_id' => ['required', 'integer', 'distinct', Rule::exists('payment_methods', 'id')],
            'methods.*.identifier' => ['nullable', 'string', 'max:160'],
            'methods.*.bank_name' => ['nullable', 'string', 'max:160'],
            'methods.*.account_holder_name' => ['nullable', 'string', 'max:160'],
            'methods.*.accounts' => ['nullable', 'array', 'max:20'],
            'methods.*.accounts.*.label' => ['nullable', 'string', 'max:120'],
            'methods.*.accounts.*.bank_name' => ['required', 'string', 'max:160'],
            'methods.*.accounts.*.account_number' => ['required', 'string', 'max:160'],
            'methods.*.accounts.*.account_holder_name' => ['required', 'string', 'max:160'],
            'methods.*.accounts.*.branch_name' => ['nullable', 'string', 'max:160'],
            'methods.*.accounts.*.currency' => ['required', 'string', 'size:3'],
            'methods.*.accounts.*.is_default' => ['nullable', 'boolean'],
        ]);

        $business->loadMissing('district.city.country', 'paymentSetting');
        $countryId = $business->district?->city?->country_id;
        $methodIds = collect($validated['methods'])->pluck('payment_method_id')->values();
        $catalog = PaymentMethod::query()
            ->whereIn('id', $methodIds)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('country_id', $countryId)->orWhereNull('country_id'))
            ->get()
            ->keyBy('id');
        abort_unless($catalog->count() === $methodIds->unique()->count(), HTTP_UNPROCESSABLE_ENTITY, 'One or more selected payment methods are not available for this country.');

        foreach ($validated['methods'] as $methodInput) {
            $method = $catalog->get($methodInput['payment_method_id']);
            if ($method->code === 'bank_transfer' && blank($methodInput['accounts'] ?? null)) {
                abort(HTTP_UNPROCESSABLE_ENTITY, 'Bank Transfer requires at least one bank account.');
            }
            if ($method->requires_identifier && blank($methodInput['identifier'] ?? null)) {
                if ($method->code === 'bank_transfer' && filled($methodInput['accounts'] ?? null)) {
                    continue;
                }
                abort(HTTP_UNPROCESSABLE_ENTITY, "{$method->name} requires a payment or account number.");
            }
            if ($method->code === 'bank_transfer' && blank($methodInput['bank_name'] ?? null)) {
                abort(HTTP_UNPROCESSABLE_ENTITY, 'Bank transfer requires a bank name.');
            }
        }

        DB::transaction(function () use ($business, $validated, $methodIds, $catalog): void {
            $business->paymentMethods()->whereNotIn('payment_method_id', $methodIds->all())->update(['is_enabled' => false]);

            foreach ($validated['methods'] as $index => $methodInput) {
                $configuration = $business->paymentMethods()->updateOrCreate(
                    ['payment_method_id' => $methodInput['payment_method_id']],
                    [
                        'identifier' => filled($methodInput['identifier'] ?? null) ? trim($methodInput['identifier']) : null,
                        'bank_name' => filled($methodInput['bank_name'] ?? null) ? trim($methodInput['bank_name']) : null,
                        'account_holder_name' => filled($methodInput['account_holder_name'] ?? null) ? trim($methodInput['account_holder_name']) : null,
                        'status' => 'active',
                        'is_enabled' => true,
                        'sort_order' => $index,
                        'rejection_reason' => null,
                    ],
                );

                if ($catalog->get($methodInput['payment_method_id'])->code === 'bank_transfer') {
                    $configuration->accounts()->delete();
                    $accounts = collect($methodInput['accounts'] ?? [])->values();
                    $defaultIndex = $accounts->search(fn (array $account) => (bool) ($account['is_default'] ?? false));
                    $defaultIndex = $defaultIndex === false ? 0 : $defaultIndex;
                    $configuration->accounts()->createMany($accounts->map(function (array $account, int $accountIndex) use ($defaultIndex): array {
                        return [
                            'label' => filled($account['label'] ?? null) ? trim($account['label']) : null,
                            'bank_name' => trim($account['bank_name']),
                            'account_number' => trim($account['account_number']),
                            'account_holder_name' => trim($account['account_holder_name']),
                            'branch_name' => filled($account['branch_name'] ?? null) ? trim($account['branch_name']) : null,
                            'currency' => strtoupper($account['currency']),
                            'is_default' => $accountIndex === $defaultIndex,
                            'is_enabled' => true,
                            'sort_order' => $accountIndex,
                        ];
                    })->all());
                } else {
                    $configuration->accounts()->delete();
                }
            }

            $business->paymentSetting()->updateOrCreate(
                ['business_id' => $business->id],
                [
                    'provider' => $business->paymentSetting?->provider ?? 'manual',
                    'currency' => $business->paymentSetting?->currency ?? 'TZS',
                    'commission_rate' => $business->paymentSetting?->commission_rate ?? SystemSetting::valueFor('payments.default_commission_rate'),
                    'commission_basis' => $business->paymentSetting?->commission_basis ?? 'subtotal_excluding_tax',
                    'fee_bearer' => $business->paymentSetting?->fee_bearer ?? 'business',
                    'settlement_mode' => $business->paymentSetting?->settlement_mode ?? 'manual_hold',
                    'payment_timing' => $validated['payment_timing'] ?? $business->paymentSetting?->payment_timing ?? 'after_approval',
                ],
            );
        });

        return $this->paymentMethods($business->fresh());
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
                'country_phone_code' => $business->district?->city?->country?->phone_code,
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
        $this->ensureOnlineSettlementUnavailable();
        $validated = $request->validate([
            'destination_type' => ['required', 'in:mobile_money'],
            'provider' => ['required', 'string', 'max:80'],
            'wallet_id' => ['nullable', 'string', 'max:160'],
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
        abort_if(
            BusinessPayoutAccount::query()->where('account_number', $accountNumber)->exists(),
            HTTP_UNPROCESSABLE_ENTITY,
            'This settlement phone or account number is already registered.',
        );
        $walletId = filled($validated['wallet_id'] ?? null) ? trim($validated['wallet_id']) : null;
        abort_if(
            $walletId && BusinessPayoutAccount::query()->where('wallet_id', $walletId)->exists(),
            HTTP_UNPROCESSABLE_ENTITY,
            'This AzamPay wallet ID is already registered.',
        );

        $account = DB::transaction(fn () => $business->payoutAccounts()->create([
            'gateway' => 'azampay',
            'wallet_id' => $walletId,
            'destination_type' => 'mobile_money',
            'provider' => $provider->code,
            'account_number' => $accountNumber,
            'phone_number' => $accountNumber,
            'account_holder_name' => trim($validated['account_holder_name']),
            'currency' => strtoupper($validated['currency'] ?? $business->paymentSetting?->currency ?? 'TZS'),
            'country_id' => $country?->id,
            'verification_status' => 'pending',
            'status' => 'pending',
            'is_default' => false,
        ]));

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($account)], 'Payout account submitted for Paperstic verification.', 201);
    }

    public function updatePayoutAccount(Request $request, Business $business, BusinessPayoutAccount $payoutAccount)
    {
        $this->authorizePayoutSettings($business);
        $this->ensureOnlineSettlementUnavailable();
        abort_unless((int) $payoutAccount->business_id === (int) $business->id, HTTP_NOT_FOUND, 'Payout account not found.');
        abort_unless(
            $payoutAccount->status === 'rejected' || $payoutAccount->verification_status === 'rejected',
            HTTP_UNPROCESSABLE_ENTITY,
            'Verified active payout accounts cannot be edited. Submit a new account for verification.',
        );

        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:80'],
            'wallet_id' => ['nullable', 'string', 'max:160'],
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
        abort_if(
            BusinessPayoutAccount::query()
                ->where('account_number', $accountNumber)
                ->whereKeyNot($payoutAccount->getKey())
                ->exists(),
            HTTP_UNPROCESSABLE_ENTITY,
            'This settlement phone or account number is already registered.',
        );
        $walletId = filled($validated['wallet_id'] ?? null) ? trim($validated['wallet_id']) : null;
        abort_if(
            $walletId && BusinessPayoutAccount::query()
                ->where('wallet_id', $walletId)
                ->whereKeyNot($payoutAccount->getKey())
                ->exists(),
            HTTP_UNPROCESSABLE_ENTITY,
            'This AzamPay wallet ID is already registered.',
        );

        DB::transaction(function () use ($payoutAccount, $provider, $validated, $accountNumber, $business): void {
            $payoutAccount->update([
                'provider' => $provider->code,
                'wallet_id' => filled($validated['wallet_id'] ?? null) ? trim($validated['wallet_id']) : null,
                'account_number' => $accountNumber,
                'phone_number' => $accountNumber,
                'account_holder_name' => trim($validated['account_holder_name']),
                'currency' => strtoupper($validated['currency'] ?? $business->paymentSetting?->currency ?? 'TZS'),
                'verification_status' => 'pending',
                'status' => 'pending',
                'is_default' => false,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ]);
        });

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($payoutAccount->fresh())], 'Payout account updated and returned for verification.');
    }

    public function makeDefaultPayoutAccount(Business $business, BusinessPayoutAccount $payoutAccount)
    {
        $this->authorizePayoutSettings($business);
        $this->ensureOnlineSettlementUnavailable();
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
        DB::transaction(function () use ($payment, $file, $business, $request): void {
            $payment->update([
                'proof_path' => $file->store("onboarding-payments/{$business->uuid}", 'local'),
                'proof_filename' => $file->getClientOriginalName(),
                'uploaded_by' => $request->user()->id,
                'status' => $payment->status === 'paid' ? 'paid' : 'submitted',
            ]);
        });

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

    private function authorizePaymentSettings(Business $business): void
    {
        $role = $this->authorizePaymentAccess($business, false);
        abort_unless(in_array($role, ['owner', 'vendor_manager', 'business_manager', 'manager'], true), HTTP_FORBIDDEN, 'Only business management can manage payment settings.');
    }

    private function ensureOnlineSettlementUnavailable(): void
    {
        abort(HTTP_CONFLICT, 'Online settlement is not available yet. Configure direct payment methods while Paperstic completes the online payment integration.');
    }

    private function payoutAccountData(BusinessPayoutAccount $account): array
    {
        return [
            'uuid' => $account->uuid,
            'wallet_id' => $account->maskedWalletId(),
            'destination_type' => $account->destination_type,
            'provider' => $account->provider,
            'account_number' => $account->maskedAccountNumber(),
            'phone_number' => $account->maskedPhoneNumber(),
            'account_holder_name' => $account->account_holder_name,
            'currency' => $account->currency,
            'verification_status' => $account->verification_status,
            'status' => $account->status,
            'is_default' => (bool) $account->is_default,
            'verified_at' => $account->verified_at?->toIso8601String(),
            'rejection_reason' => $account->rejection_reason,
        ];
    }

    private function paymentMethodData(PaymentMethod $method, ?BusinessPaymentMethod $configuration): array
    {
        return [
            'id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'identifier_label' => $method->identifier_label,
            'instructions' => $method->instructions,
            'logo_path' => $method->logo_path,
            'requires_identifier' => (bool) $method->requires_identifier,
            'selected' => (bool) $configuration?->is_enabled,
            'configuration' => $configuration ? [
                'uuid' => $configuration->uuid,
                'identifier' => $configuration->identifier,
                'bank_name' => $configuration->bank_name,
                'account_holder_name' => $configuration->account_holder_name,
                'status' => $configuration->status,
                'is_enabled' => (bool) $configuration->is_enabled,
                'sort_order' => $configuration->sort_order,
                'accounts' => $configuration->relationLoaded('accounts') ? $configuration->accounts->map(fn (BusinessPaymentMethodAccount $account) => [
                    'uuid' => $account->uuid,
                    'label' => $account->label,
                    'bank_name' => $account->bank_name,
                    'account_number' => $account->account_number,
                    'account_holder_name' => $account->account_holder_name,
                    'branch_name' => $account->branch_name,
                    'currency' => $account->currency,
                    'is_default' => (bool) $account->is_default,
                    'is_enabled' => (bool) $account->is_enabled,
                    'sort_order' => $account->sort_order,
                ])->values() : [],
            ] : null,
        ];
    }
}
