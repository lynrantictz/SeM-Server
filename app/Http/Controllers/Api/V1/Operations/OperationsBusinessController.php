<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessPayoutAccount;
use App\Models\Business\BusinessType;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\ComplianceDocumentType;
use App\Models\Business\OnboardingPackage;
use App\Models\Location\City;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Models\Section\Section;
use App\Models\Section\ServicePoint;
use App\Models\Payment\MobileMoneyProvider;
use App\Services\Business\BusinessActivationService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Models\Audit;

class OperationsBusinessController extends BaseController
{
    public function __construct(private readonly BusinessActivationService $activation)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,active,inactive'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'per_page' => ['nullable', 'in:10,25,50,75,100,all'],
        ]);

        $businessQuery = Business::query()
            ->select(['id', 'uuid', 'name', 'vendor_id', 'business_type_id', 'tin', 'location', 'district_id', 'is_active', 'created_at'])
            ->with(['vendor:id,name', 'type:id,name', 'district:id,name,city_id', 'district.city:id,name,country_id', 'district.city.country:id,name,iso2'])
            ->withCount('complianceDocuments')
            ->withCount(['complianceDocuments as pending_documents_count' => fn ($query) => $query->whereIn('status', ['pending', 'in_review', 'needs_review'])])
            ->when(!empty($filters['search']), function ($query) use ($filters): void {
                $term = '%' . trim($filters['search']) . '%';
                $query->where(function ($search) use ($term): void {
                    $search->where('name', 'ilike', $term)
                        ->orWhere('tin', 'ilike', $term)
                        ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'ilike', $term));
                });
            })
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($query) => $query->where('is_active', ($filters['status'] ?? null) === 'active'))
            ->when(!empty($filters['country_id']), fn ($query) => $query->whereHas('district.city', fn ($city) => $city->where('country_id', $filters['country_id'])))
            ->when(!empty($filters['business_type_id']), fn ($query) => $query->where('business_type_id', $filters['business_type_id']))
            ->when(!empty($filters['city_id']), fn ($query) => $query->whereHas('district.city', fn ($city) => $city->whereKey($filters['city_id'])))
            ->orderByDesc('created_at');

        $perPage = $filters['per_page'] ?? '10';
        if ($perPage === 'all') {
            $businessCollection = $businessQuery->get();
            $businesses = [
                'data' => $businessCollection->map(fn (Business $business) => $this->businessData($business)),
                'current_page' => 1,
                'last_page' => 1,
                'total' => $businessCollection->count(),
            ];
        } else {
            $paginatedBusinesses = $businessQuery->paginate((int) $perPage)->withQueryString();
            $businesses = $paginatedBusinesses->through(fn (Business $business) => $this->businessData($business));
        }

        return $this->sendResponse([
            'businesses' => $businesses,
            ...Cache::remember('operations.business-directory.filter-options', now()->addMinutes(10), fn () => [
                'countries' => Country::query()->select(['id', 'name', 'iso2'])->orderBy('name')->get(),
                'business_types' => BusinessType::query()->select(['id', 'name'])->orderBy('name')->get(),
                'cities' => City::query()->select(['id', 'name'])->orderBy('name')->get(),
            ]),
        ], 'Operations businesses retrieved successfully.');
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $business = Business::query()
            ->with([
                'vendor:id,name',
                'vendor.users:id,name,email,phone,is_active,type',
                'type:id,name',
                'district:id,name,city_id',
                'district.city:id,name,country_id',
                'district.city.country:id,name,iso2',
                'timezoneDefinition:id,identifier,name',
                'contacts:id,business_id,contact,is_active',
                'orderingChannels:id,slug,name',
                'openingHours:id,business_id,day_of_week,sort_order,opens_at,closes_at,is_closed',
                'paymentSetting',
                'onboardingPayment.onboardingPackage',
                'payoutAccounts:id,uuid,business_id,gateway,wallet_id,destination_type,provider,account_number,phone_number,account_holder_name,currency,verification_status,status,is_default,verified_at,rejection_reason,verification_document_filename,verification_document_uploaded_at,country_id',
                'payoutAccounts.country:id,name,iso2',
            ])
            ->withCount(['complianceDocuments as pending_documents_count' => fn ($query) => $query->whereIn('status', ['pending', 'in_review', 'needs_review'])])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $documents = $request->user()->can('operations.kyc.view')
            ? $business->complianceDocuments()->with(['reviewer:id,name'])->latest()->get()->map(fn (ComplianceDocument $document) => $this->documentData($document))
            : collect();
        $documentRequirements = $this->documentRequirements($business, $documents);
        $activation = $this->activation->status($business);

        return $this->sendResponse([
            'business' => $this->businessData($business),
            'documents' => $documents,
            'document_requirements' => $documentRequirements,
            'onboarding_payment' => $this->onboardingPaymentData($business->onboardingPayment),
            'onboarding_packages' => OnboardingPackage::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'price', 'currency']),
            'activation' => $activation,
        ], 'Operations business details retrieved successfully.');
    }

    public function updateOnboardingPayment(Request $request, string $uuid): JsonResponse
    {
        $business = Business::query()->with('onboardingPayment')->where('uuid', $uuid)->firstOrFail();
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:onboarding_packages,id'],
            'amount_due' => ['required', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'status' => ['required', 'in:pending,submitted,partially_paid,paid,waived,refunded'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_reference' => ['nullable', 'string', 'max:160'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $package = \App\Models\Business\OnboardingPackage::query()->where('is_active', true)->findOrFail($validated['package_id']);

        $payment = DB::transaction(fn () => $business->onboardingPayment()->updateOrCreate(
            ['business_id' => $business->id],
            [...$validated, 'package' => $package->name, 'amount_paid' => $validated['amount_paid'] ?? 0, 'currency' => strtoupper($validated['currency'])],
        ));

        return $this->sendResponse(['onboarding_payment' => $this->onboardingPaymentData($payment)], 'One-time onboarding payment updated.');
    }

    public function updatePaymentSetting(Request $request, string $uuid): JsonResponse
    {
        $business = Business::query()->with(['paymentSetting', 'onboardingPayment'])->where('uuid', $uuid)->firstOrFail();
        $validated = $request->validate([
            'provider' => ['required', 'in:azampay'],
            'currency' => ['required', 'string', 'size:3'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_basis' => ['required', 'in:subtotal_excluding_tax'],
            'fee_bearer' => ['required', 'in:business'],
            'settlement_mode' => ['required', 'in:manual_hold,manual_payout,automatic_payout'],
            'settlement_requirement' => ['required', 'in:required,not_required,alternative_method'],
            'is_checkout_enabled' => ['required', 'boolean'],
            'is_settlement_enabled' => ['required', 'boolean'],
        ]);

        $readiness = $this->activation->status($business);
        $onboardingPaid = in_array($business->onboardingPayment?->status, ['paid', 'waived'], true);
        $defaultAccount = $business->payoutAccounts()
            ->where('is_default', true)
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->exists();

        if ($validated['is_checkout_enabled'] && (! $business->is_active || ! $readiness['documents_approved'] || ! $onboardingPaid)) {
            return $this->sendError('Checkout cannot be enabled until the business is active, mandatory documents are approved, and the onboarding payment is verified or waived.', [
                'business_active' => (bool) $business->is_active,
                'documents_approved' => $readiness['documents_approved'],
                'onboarding_payment_verified' => $onboardingPaid,
            ], HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($validated['is_settlement_enabled'] && ($validated['settlement_requirement'] === 'not_required' || ! $validated['is_checkout_enabled'] || ! $defaultAccount)) {
            return $this->sendError('Settlement cannot be enabled until checkout is enabled and a verified active default settlement account exists.', [
                'verified_default_account' => $defaultAccount,
                'settlement_requirement' => $validated['settlement_requirement'],
            ], HTTP_UNPROCESSABLE_ENTITY);
        }

        $setting = DB::transaction(fn () => $business->paymentSetting()->updateOrCreate(
            ['business_id' => $business->id],
            [...$validated, 'currency' => strtoupper($validated['currency'])],
        ));

        return $this->sendResponse([
            'payment_setting' => $this->paymentSettingData($setting),
        ], 'Payment configuration updated successfully.');
    }

    public function storePayoutAccount(Request $request, string $uuid): JsonResponse
    {
        $business = Business::query()->with('district.city.country')->where('uuid', $uuid)->firstOrFail();
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:80', 'required_without:wallet_id'],
            'wallet_id' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'regex:/^[1-9][0-9]{6,14}$/', 'required_without:wallet_id'],
            'account_holder_name' => ['required', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $country = $business->district?->city?->country;
        $walletId = filled($validated['wallet_id'] ?? null) ? trim($validated['wallet_id']) : null;
        $provider = $validated['provider']
            ? MobileMoneyProvider::query()->where('gateway', 'azampay')->where('code', $validated['provider'])->where('is_active', true)->when($country?->id, fn ($query) => $query->where(fn ($countryQuery) => $countryQuery->where('country_id', $country->id)->orWhereNull('country_id')))->firstOrFail()
            : null;
        $accountNumber = filled($validated['account_number'] ?? null)
            ? app(PhoneNumberNormalizer::class)->normalize($validated['account_number'], $country?->iso2)
            : null;
        abort_if($accountNumber && BusinessPayoutAccount::query()->where('account_number', $accountNumber)->exists(), HTTP_UNPROCESSABLE_ENTITY, 'This settlement phone or account number is already registered.');
        abort_if($walletId && BusinessPayoutAccount::query()->where('wallet_id', $walletId)->exists(), HTTP_UNPROCESSABLE_ENTITY, 'This AzamPay wallet ID is already registered.');
        $account = DB::transaction(function () use ($business, $walletId, $provider, $accountNumber, $validated, $country, $request): BusinessPayoutAccount {
            $lockedBusiness = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_if(
                $lockedBusiness->payoutAccounts()->where('status', 'active')->exists(),
                HTTP_UNPROCESSABLE_ENTITY,
                'This business already has an active settlement account. Suspend it before activating another account.',
            );

            return $lockedBusiness->payoutAccounts()->create([
                'gateway' => 'azampay',
                'wallet_id' => $walletId,
                'destination_type' => 'mobile_money',
                'provider' => $provider?->code,
                'account_number' => $accountNumber,
                'phone_number' => $accountNumber,
                'account_holder_name' => trim($validated['account_holder_name']),
                'currency' => strtoupper($validated['currency'] ?? $business->paymentSetting?->currency ?? 'TZS'),
                'country_id' => $country?->id,
                'verification_status' => 'verified',
                'status' => 'active',
                'is_default' => true,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'metadata' => ['created_by_operations' => true],
            ]);
        });

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($account)], 'Settlement account created and activated.', 201);
    }

    public function updatePayoutAccount(Request $request, string $uuid, string $accountUuid): JsonResponse
    {
        $business = Business::query()->with('district.city.country')->where('uuid', $uuid)->firstOrFail();
        $account = $business->payoutAccounts()->where('uuid', $accountUuid)->firstOrFail();
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:80', 'required_without:wallet_id'],
            'wallet_id' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'regex:/^[1-9][0-9]{6,14}$/', 'required_without:wallet_id'],
            'account_holder_name' => ['required', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $country = $business->district?->city?->country;
        $walletId = filled($validated['wallet_id'] ?? null) ? trim($validated['wallet_id']) : null;
        $provider = $validated['provider']
            ? MobileMoneyProvider::query()->where('gateway', 'azampay')->where('code', $validated['provider'])->where('is_active', true)->when($country?->id, fn ($query) => $query->where(fn ($countryQuery) => $countryQuery->where('country_id', $country->id)->orWhereNull('country_id')))->firstOrFail()
            : null;
        $accountNumber = filled($validated['account_number'] ?? null)
            ? app(PhoneNumberNormalizer::class)->normalize($validated['account_number'], $country?->iso2)
            : null;
        abort_if($accountNumber && BusinessPayoutAccount::query()->where('account_number', $accountNumber)->whereKeyNot($account->getKey())->exists(), HTTP_UNPROCESSABLE_ENTITY, 'This settlement phone or account number is already registered.');
        abort_if($walletId && BusinessPayoutAccount::query()->where('wallet_id', $walletId)->whereKeyNot($account->getKey())->exists(), HTTP_UNPROCESSABLE_ENTITY, 'This AzamPay wallet ID is already registered.');
        DB::transaction(function () use ($account, $provider, $validated, $accountNumber, $business, $request): void {
            $lockedBusiness = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_if(
                $lockedBusiness->payoutAccounts()->where('status', 'active')->whereKeyNot($account->getKey())->exists(),
                HTTP_UNPROCESSABLE_ENTITY,
                'This business already has another active settlement account. Suspend it before updating this account.',
            );
            $account->update([
            'provider' => $provider?->code,
            'wallet_id' => filled($validated['wallet_id'] ?? null) ? trim($validated['wallet_id']) : null,
            'account_number' => $accountNumber,
            'phone_number' => $accountNumber,
            'account_holder_name' => trim($validated['account_holder_name']),
            'currency' => strtoupper($validated['currency'] ?? $business->paymentSetting?->currency ?? 'TZS'),
            'verification_status' => 'verified',
            'status' => 'active',
            'is_default' => true,
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'rejection_reason' => null,
            'metadata' => ['updated_by_operations' => true],
            ]);
        });

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($account->fresh())], 'Settlement account updated and activated.');
    }

    public function uploadPayoutVerificationDocument(Request $request, string $uuid, string $accountUuid): JsonResponse
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $account = $business->payoutAccounts()->where('uuid', $accountUuid)->firstOrFail();
        $validated = $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        if ($account->verification_document_path) {
            Storage::disk('local')->delete($account->verification_document_path);
        }
        $file = $validated['file'];
        DB::transaction(function () use ($account, $file, $business, $request): void {
            $account->update([
            'verification_document_path' => $file->store("payout-verification/{$business->uuid}", 'local'),
            'verification_document_filename' => $file->getClientOriginalName(),
            'verification_document_uploaded_by' => $request->user()->id,
            'verification_document_uploaded_at' => now(),
            ]);
        });
        return $this->sendResponse(['payout_account' => $this->payoutAccountData($account->fresh())], 'AzamPay verification document uploaded.');
    }

    public function downloadPayoutVerificationDocument(string $uuid, string $accountUuid)
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $account = $business->payoutAccounts()->where('uuid', $accountUuid)->firstOrFail();
        abort_unless($account->verification_document_path, 404, 'No verification document has been uploaded.');
        abort_unless(Storage::disk('local')->exists($account->verification_document_path), 404, 'The verification document is no longer available.');
        return Storage::disk('local')->response($account->verification_document_path, $account->verification_document_filename);
    }

    public function reviewPayoutAccount(Request $request, string $uuid, string $accountUuid): JsonResponse
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $account = $business->payoutAccounts()->where('uuid', $accountUuid)->firstOrFail();
        $validated = $request->validate(['decision' => ['required', 'in:approved,returned,suspended,reactivated'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $approved = in_array($validated['decision'], ['approved', 'reactivated'], true);
        $suspended = $validated['decision'] === 'suspended';
        DB::transaction(function () use ($account, $approved, $suspended, $validated, $request, $business): void {
            $lockedBusiness = Business::query()->lockForUpdate()->findOrFail($business->id);
            abort_if(
                $approved && $lockedBusiness->payoutAccounts()->where('status', 'active')->whereKeyNot($account->getKey())->exists(),
                HTTP_UNPROCESSABLE_ENTITY,
                'This business already has another active settlement account. Suspend it before approving this account.',
            );
            $account->update([
            'verification_status' => $approved ? 'verified' : ($suspended ? 'verified' : 'needs_update'),
            'status' => $approved ? 'active' : ($suspended ? 'suspended' : 'pending'),
            'is_default' => $approved,
            'verified_by' => $approved ? $request->user()->id : $account->verified_by,
            'verified_at' => $approved ? now() : $account->verified_at,
            'rejection_reason' => $approved ? null : ($validated['reason'] ?? 'Settlement account requires correction.'),
            ]);
        });

        return $this->sendResponse(['payout_account' => $this->payoutAccountData($account->fresh())], 'Settlement account status updated.');
    }

    public function makeDefaultPayoutAccount(string $uuid, string $accountUuid): JsonResponse
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $account = $business->payoutAccounts()->where('uuid', $accountUuid)->firstOrFail();
        abort_unless($account->status === 'active' && $account->verification_status === 'verified', HTTP_UNPROCESSABLE_ENTITY, 'Only a verified active account can be made default.');
        DB::transaction(function () use ($business, $account): void {
            $business->payoutAccounts()->update(['is_default' => false]);
            $account->update(['is_default' => true]);
        });
        return $this->sendResponse(['payout_account' => $this->payoutAccountData($account->fresh())], 'Default settlement account updated.');
    }

    public function payoutAccountHistory(string $uuid, string $accountUuid): JsonResponse
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $account = $business->payoutAccounts()->where('uuid', $accountUuid)->firstOrFail();
        $audits = Audit::query()
            ->where('auditable_type', BusinessPayoutAccount::class)
            ->where('auditable_id', $account->getKey())
            ->with('user:id,name,email')
            ->latest()
            ->paginate(25);

        return $this->sendResponse([
            'history' => $audits->through(fn (Audit $audit) => [
                'id' => $audit->id,
                'event' => $audit->event,
                'user' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->name,
                    'email' => $audit->user->email,
                ] : null,
                'performed_by' => $audit->user ? [
                    'id' => $audit->user->id,
                    'name' => $audit->user->name,
                    'email' => $audit->user->email,
                ] : [
                    'id' => null,
                    'name' => 'System',
                    'email' => null,
                ],
                'old_values' => $audit->old_values,
                'new_values' => $audit->new_values,
                'url' => $audit->url,
                'ip_address' => $audit->ip_address,
                'created_at' => $audit->created_at?->toIso8601String(),
            ]),
        ], 'Settlement account history retrieved successfully.');
    }

    public function uploadOnboardingProof(Request $request, string $uuid): JsonResponse
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $payment = $business->onboardingPayment()->firstOrFail();
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

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

        return $this->sendResponse(['onboarding_payment' => $this->onboardingPaymentData($payment->fresh('onboardingPackage'))], 'Onboarding payment proof uploaded.');
    }

    public function downloadOnboardingProof(string $uuid)
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $payment = $business->onboardingPayment()->firstOrFail();
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), HTTP_NOT_FOUND, 'No onboarding payment proof has been uploaded.');

        return Storage::disk('local')->response(
            $payment->proof_path,
            $payment->proof_filename ?: 'onboarding-payment-proof.pdf',
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline'],
        );
    }

    public function team(Request $request, string $uuid): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'in:10,25,50,75,100,all'],
        ]);

        $business = Business::query()->where('uuid', $uuid)->firstOrFail();

        $vendorMembers = DB::table('vendor_user as membership')
            ->join('users as team_user', 'team_user.id', '=', 'membership.user_id')
            ->where('membership.vendor_id', $business->vendor_id)
            ->select([
                'team_user.uuid', 'team_user.name', 'team_user.code', 'team_user.email', 'team_user.phone', 'team_user.last_login_at', 'team_user.last_login_portal',
                'team_user.type as user_type', DB::raw("'vendor' as source"),
                'membership.role', DB::raw('NULL::varchar as title'), 'membership.access_scope',
                'membership.is_primary', 'membership.is_active as membership_active', 'team_user.is_active as user_active',
                'membership.invited_at', 'membership.accepted_at', DB::raw('NULL::timestamp as activated_at'),
                DB::raw('NULL::timestamp as deactivated_at'), 'membership.revoked_at',
            ]);

        $businessMembers = DB::table('business_user as membership')
            ->join('users as team_user', 'team_user.id', '=', 'membership.user_id')
            ->leftJoin('business_staff_roles as staff_role', 'staff_role.id', '=', 'membership.business_staff_role_id')
            ->where('membership.business_id', $business->id)
            ->select([
                'team_user.uuid', 'team_user.name', 'team_user.code', 'team_user.email', 'team_user.phone', 'team_user.last_login_at', 'team_user.last_login_portal',
                'team_user.type as user_type', DB::raw("'business' as source"),
                'membership.business_role as role', 'membership.title', DB::raw("'this_business' as access_scope"),
                DB::raw('false as is_primary'), 'membership.is_active as membership_active', 'team_user.is_active as user_active',
                DB::raw('NULL::timestamp as invited_at'), DB::raw('NULL::timestamp as accepted_at'),
                'membership.activated_at', 'membership.deactivated_at', DB::raw('NULL::timestamp as revoked_at'),
            ]);

        $query = DB::query()->fromSub($vendorMembers->unionAll($businessMembers), 'team_members')
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $term = '%' . trim($search) . '%';
                $query->where(function ($searchQuery) use ($term): void {
                    $searchQuery->where('name', 'ilike', $term)
                        ->orWhere('code', 'ilike', $term)
                        ->orWhere('email', 'ilike', $term)
                        ->orWhere('phone', 'ilike', $term)
                        ->orWhere('role', 'ilike', $term)
                        ->orWhere('title', 'ilike', $term);
                });
            })
            ->orderByDesc('membership_active')
            ->orderBy('name');

        $perPage = $filters['per_page'] ?? '10';
        if ($perPage === 'all') {
            $members = $query->get();
            $team = [
                'data' => $members->map(fn ($member) => $this->teamMemberData($member))->values(),
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $members->count(),
                'total' => $members->count(),
                'from' => $members->isEmpty() ? null : 1,
                'to' => $members->count() ?: null,
            ];
        } else {
            $members = $query->paginate((int) $perPage)->withQueryString();
            $team = $members->through(fn ($member) => $this->teamMemberData($member));
        }

        return $this->sendResponse(['team' => $team], 'Business team retrieved successfully.');
    }

    public function sections(string $uuid): JsonResponse
    {
        $business = Business::query()->where('uuid', $uuid)->firstOrFail();

        $sections = Section::query()
            ->where('business_id', $business->id)
            ->withCount(['subs', 'servicePoints'])
            ->with([
                'servicePoints' => fn ($query) => $this->servicePointQuery($query),
                'subs' => function ($query): void {
                    $query->withCount('servicePoints')
                        ->with(['servicePoints' => fn ($servicePointQuery) => $this->servicePointQuery($servicePointQuery)])
                        ->orderBy('name');
                },
            ])
            ->orderBy('name')
            ->get();

        return $this->sendResponse([
            'sections' => $sections->map(function (Section $section): array {
                $subsections = $section->subs->map(fn ($subSection) => [
                    'uuid' => $subSection->uuid,
                    'name' => $subSection->name,
                    'description' => $subSection->description,
                    'is_active' => (bool) $subSection->is_active,
                    'service_point_count' => (int) $subSection->service_points_count,
                    'service_points' => $subSection->servicePoints->map(fn (ServicePoint $servicePoint) => $this->servicePointData($servicePoint))->values(),
                ])->values();

                $directServicePoints = $section->servicePoints->map(fn (ServicePoint $servicePoint) => $this->servicePointData($servicePoint))->values();

                return [
                    'uuid' => $section->uuid,
                    'name' => $section->name,
                    'description' => $section->description,
                    'is_active' => (bool) $section->is_active,
                    'subsection_count' => (int) $section->subs_count,
                    'service_point_count' => $directServicePoints->count() + $subsections->sum('service_point_count'),
                    'direct_service_points' => $directServicePoints,
                    'subsections' => $subsections,
                ];
            })->values(),
        ], 'Business sections and service points retrieved successfully.');
    }

    public function orders(Request $request, string $uuid): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,pending,processing,ready,served,completed,cancelled,refunded'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort_by' => ['nullable', 'in:created_at,total_amount'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'in:10,25,50,75,100,all'],
        ]);

        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $query = Order::query()
            ->where('business_id', $business->id)
            ->with([
                'status:id,name',
                'paymentStatus:id,name',
                'paymentMethod:id,name',
                'payment:payments.id,payments.order_id,payments.account_number,payments.provider,payments.operator,payments.msisdn,payments.amount,payments.currency,payments.status,payments.confirmed_at,payments.paid_at',
                'customer:id,phone,phone_e164',
                'approver:id,name',
                'assignee:id,name',
                'servicePoint:id,type,label,display_name,section_id,sub_section_id',
                'servicePoint.section:id,name',
                'servicePoint.subSection:id,name',
                'items:id,order_id,item_id,quantity,unit_price,final_price,total_amount,comment',
                'items.item:id,uuid,name',
                'items.options:id,order_item_id,name,price_adjustment',
                'items.options.itemOption:id,uuid',
                'statusHistories.fromStatus:id,name',
                'statusHistories.toStatus:id,name',
                'statusHistories.changedBy:id,name',
            ])
            ->withCount('items')
            ->when(!empty($filters['search']), function ($query) use ($filters): void {
                $term = '%' . trim($filters['search']) . '%';
                $query->where(function ($search) use ($term): void {
                    $search->where('number', 'ilike', $term)
                        ->orWhereHas('customer', fn ($customer) => $customer
                            ->where('phone_e164', 'ilike', $term)
                            ->orWhere('phone', 'ilike', $term));
                });
            })
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($query) => $query->whereHas('status', fn ($status) => $status->whereRaw('LOWER(name) = ?', [$filters['status']])))
            ->when(!empty($filters['date_from']), fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_direction'] ?? 'desc');

        $perPage = $filters['per_page'] ?? '10';
        if ($perPage === 'all') {
            $orders = $query->get();
            $orderData = [
                'data' => $orders->map(fn (Order $order) => $this->operationsOrderData($order))->values(),
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $orders->count(),
                'total' => $orders->count(),
                'from' => $orders->isEmpty() ? null : 1,
                'to' => $orders->count() ?: null,
            ];
        } else {
            $orders = $query->paginate((int) $perPage)->withQueryString();
            $orderData = [
                'data' => $orders->getCollection()->map(fn (Order $order) => $this->operationsOrderData($order))->values(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ];
        }

        return $this->sendResponse(['orders' => $orderData], 'Business orders retrieved successfully.');
    }

    public function documents(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,pending,in_review,needs_review,needs_update,approved,rejected,expired'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $documents = ComplianceDocument::query()
            ->whereNotNull('business_id')
            ->with(['business:id,uuid,name,vendor_id,district_id,is_active', 'business.vendor:id,name', 'business.district:id,name,city_id', 'business.district.city:id,name,country_id', 'business.district.city.country:id,name,iso2', 'uploader:id,name', 'reviewer:id,name'])
            ->when(($filters['status'] ?? 'pending') !== 'all', function ($query) use ($filters): void {
                $status = $filters['status'] ?? 'pending';
                if ($status === 'expired') {
                    $query->where(function ($expired) {
                        $expired->where('status', 'expired')
                            ->orWhere(fn ($approved) => $approved->where('status', 'approved')->whereNotNull('expires_at')->whereDate('expires_at', '<', today()));
                    });
                } elseif ($status === 'approved') {
                    $query->where('status', 'approved')->where(fn ($approved) => $approved->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()));
                } elseif ($status === 'pending') {
                    $query->whereIn('status', ['pending', 'in_review', 'needs_review', 'needs_update']);
                } else {
                    $query->where('status', $status);
                }
            })
            ->when(!empty($filters['search']), function ($query) use ($filters): void {
                $term = '%' . trim($filters['search']) . '%';
                $query->where(function ($search) use ($term): void {
                    $search->where('document_type_name', 'ilike', $term)
                        ->orWhere('document_type', 'ilike', $term)
                        ->orWhere('original_filename', 'ilike', $term)
                        ->orWhereHas('business', fn ($business) => $business->where('name', 'ilike', $term));
                });
            })
            ->when(!empty($filters['country_id']), fn ($query) => $query->whereHas('business.district.city', fn ($city) => $city->where('country_id', $filters['country_id'])))
            ->orderByRaw("CASE WHEN status IN ('pending', 'in_review', 'needs_review', 'needs_update') THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return $this->sendResponse([
            'documents' => $documents->through(fn (ComplianceDocument $document) => $this->documentData($document)),
        ], 'Business compliance documents retrieved successfully.');
    }

    public function download(string $uuid)
    {
        $document = ComplianceDocument::query()->where('uuid', $uuid)->whereNotNull('business_id')->firstOrFail();
        abort_unless(Storage::disk($document->disk)->exists($document->storage_path), 404, 'The document file is unavailable.');

        return Storage::disk($document->disk)->response($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="' . addcslashes($document->original_filename, '"\\') . '"',
        ]);
    }

    public function review(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,returned'],
            'reason' => ['required_if:decision,returned', 'nullable', 'string', 'max:2000'],
        ]);

        $permission = $validated['decision'] === 'approved' ? 'operations.kyc.approve' : 'operations.kyc.return';
        abort_unless($request->user()->can($permission), 403, 'You do not have permission to make this document decision.');

        $document = ComplianceDocument::query()
            ->where('uuid', $uuid)
            ->whereNotNull('business_id')
            ->firstOrFail();
        $effectiveStatus = $document->status === 'approved' && $document->expires_at?->isPast() ? 'expired' : $document->status;
        abort_unless(in_array($effectiveStatus, ['pending', 'in_review', 'needs_review', 'needs_update', 'approved'], true), 409, 'This document is not available for an operations decision.');

        DB::transaction(function () use ($document, $validated, $request): void {
            $document->update([
                'status' => $validated['decision'] === 'approved' ? 'approved' : 'needs_update',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'rejection_reason' => $validated['decision'] === 'returned' ? trim($validated['reason']) : null,
            ]);

            if ($document->business && in_array($document->document_type, ['vat_registration_certificate', 'tax_exemption_certificate'], true)) {
                $document->business->update([
                    'tax_status' => $validated['decision'] === 'approved'
                        ? ($document->document_type === 'vat_registration_certificate' ? 'vat_registered' : 'tax_exempt')
                        : 'under_review',
                ]);
            }
        });

        return $this->sendResponse([
            'document' => $this->documentData($document->fresh()->load(['business:id,uuid,name', 'uploader:id,name', 'reviewer:id,name'])),
        ], $validated['decision'] === 'approved'
            ? 'Compliance document approved successfully.'
            : 'Compliance document returned to the business for update.');
    }

    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $permission = $validated['is_active'] ? 'operations.businesses.approve' : 'operations.businesses.suspend';
        abort_unless($request->user()->can($permission), 403, 'You do not have permission to change this business status.');

        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        if ($validated['is_active']) {
            $readiness = $this->activation->status($business);
            abort_if(
                ! $readiness['manual_payment_ready'],
                HTTP_UNPROCESSABLE_ENTITY,
                'This business cannot be activated until all required documents are approved and at least one payment method is approved and complete.'
            );
        }
        DB::transaction(function () use ($business, $validated): void {
            $business->update(['is_active' => $validated['is_active']]);
        });

        return $this->sendResponse([
            'business' => $this->businessData($business->fresh()->load(['vendor:id,name', 'type:id,name', 'district.city.country', 'contacts:id,business_id,contact,is_active'])),
            'activation' => $this->activation->status($business),
        ], $validated['is_active'] ? 'Business activated successfully.' : 'Business suspended successfully.');
    }

    private function businessData(Business $business): array
    {
        return [
            'uuid' => $business->uuid,
            'name' => $business->name,
            'vendor' => $business->vendor?->name,
            'business_type' => $business->type?->name,
            'tin' => $business->tin,
            'location' => $business->location,
            'google_location' => $business->google_location,
            'latitude' => $business->latitude,
            'longitude' => $business->longitude,
            'location_verified_at' => $business->location_verified_at?->toIso8601String(),
            'logo_url' => $business->logo_url,
            'image_url' => $business->image_url,
            'discovery_description' => $business->discovery_description,
            'rating' => $business->rating,
            'review_count' => (int) ($business->review_count ?? 0),
            'order_prefix' => $business->order_prefix,
            'code_prefix' => $business->code_prefix,
            'current_order_number' => $business->current_order_number,
            'tax_allowed' => (bool) $business->tax_allowed,
            'tax_status' => $business->tax_status ?: 'not_registered',
            'timezone' => $business->timezoneDefinition?->identifier ?: $business->timezone,
            'district' => $business->district?->name,
            'city' => $business->district?->city?->name,
            'country' => $business->district?->city?->country?->name,
            'country_iso2' => $business->district?->city?->country?->iso2,
            'country_phone_code' => $business->district?->city?->country?->phone_code,
            'is_active' => (bool) $business->is_active,
            'created_at' => $business->created_at?->toIso8601String(),
            'updated_at' => $business->updated_at?->toIso8601String(),
            'document_count' => (int) ($business->compliance_documents_count ?? 0),
            'pending_documents_count' => (int) ($business->pending_documents_count ?? 0),
            'contacts' => $business->relationLoaded('contacts') ? $business->contacts->map(fn ($contact) => [
                'id' => $contact->id,
                'contact' => $contact->contact,
                'is_active' => (bool) $contact->is_active,
            ])->values() : [],
            'vendor_team' => $business->relationLoaded('vendor') && $business->vendor?->relationLoaded('users')
                ? $business->vendor->users->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'type' => $user->type,
                    'is_active' => (bool) $user->is_active,
                    'is_primary' => (bool) $user->pivot->is_primary,
                    'role' => $user->pivot->role,
                    'access_scope' => $user->pivot->access_scope,
                ])->values()
                : [],
            'ordering_channels' => $business->relationLoaded('orderingChannels') ? $business->orderingChannels->map(fn ($channel) => [
                'slug' => $channel->slug,
                'name' => $channel->name,
                'is_enabled' => (bool) $channel->pivot->is_enabled,
            ])->values() : [],
            'opening_hours' => $business->relationLoaded('openingHours') ? $business->openingHours->sortBy(fn ($hour) => sprintf('%02d-%04d', $hour->day_of_week, $hour->sort_order))->map(fn ($hour) => [
                'day_of_week' => (int) $hour->day_of_week,
                'opens_at' => $hour->opens_at ? substr((string) $hour->opens_at, 0, 5) : null,
                'closes_at' => $hour->closes_at ? substr((string) $hour->closes_at, 0, 5) : null,
                'is_closed' => (bool) $hour->is_closed,
            ])->values() : [],
            'payment_setting' => $business->relationLoaded('paymentSetting') && $business->paymentSetting ? [
                'provider' => $business->paymentSetting->provider,
                'currency' => $business->paymentSetting->currency,
                'commission_rate' => $business->paymentSetting->commission_rate,
                'commission_basis' => $business->paymentSetting->commission_basis,
                'fee_bearer' => $business->paymentSetting->fee_bearer,
                'settlement_mode' => $business->paymentSetting->settlement_mode,
                'settlement_requirement' => $business->paymentSetting->settlement_requirement ?? 'required',
                'is_checkout_enabled' => (bool) $business->paymentSetting->is_checkout_enabled,
                'is_settlement_enabled' => (bool) $business->paymentSetting->is_settlement_enabled,
            ] : null,
            'payout_accounts' => $business->relationLoaded('payoutAccounts') ? $business->payoutAccounts->map(fn ($account) => [
                'uuid' => $account->uuid,
                'destination_type' => $account->destination_type,
                'provider' => $account->provider,
                'wallet_id' => $account->wallet_id,
                'account_holder_name' => $account->account_holder_name,
                'account_number' => $account->maskedAccountNumber(),
                'phone_number' => $account->phone_number,
                'currency' => $account->currency,
                'verification_status' => $account->verification_status,
                'status' => $account->status,
                'is_default' => (bool) $account->is_default,
                'verified_at' => $account->verified_at?->toIso8601String(),
                'rejection_reason' => $account->rejection_reason,
                'verification_document_filename' => $account->verification_document_filename,
                'verification_document_uploaded_at' => $account->verification_document_uploaded_at?->toIso8601String(),
                'country' => $account->country?->name,
            ])->values() : [],
        ];
    }

    private function servicePointQuery($query): void
    {
        $query->select(['id', 'uuid', 'section_id', 'sub_section_id', 'type', 'label', 'display_name', 'capacity', 'is_active', 'updated_at'])
            ->with([
                'activeCode:id,codable_id,codable_type,code,is_active',
                'orderingChannels:id,slug,name',
            ])
            ->orderBy('label');
    }

    private function operationsOrderData(Order $order): array
    {
        $point = $order->servicePoint;
        $paymentMethod = $order->paymentMethod?->name;
        $isCash = str_contains(strtolower((string) $paymentMethod), 'cash');

        return [
            'uuid' => $order->uuid,
            'number' => $order->number,
            'channel' => $order->channel,
            'status' => $order->status?->name,
            'payment_status' => $order->paymentStatus?->name,
            'payment_method' => $paymentMethod,
            'payment_type' => $isCash ? 'Cash' : ($order->payment ? 'Mobile money' : ($paymentMethod ?: 'Not recorded')),
            'payment_phone' => $order->payment?->msisdn ?: ($order->payment?->account_number ?: null),
            'payment_provider' => $order->payment?->operator ?: ($order->payment?->provider ?: null),
            'payment_paid_at' => $this->isoDate($order->payment?->paid_at ?: $order->payment?->confirmed_at),
            'approved_by' => $order->approver?->name,
            'assigned_to' => $order->assignee?->name,
            'customer_phone' => $order->customer?->phone_e164 ?: $order->customer?->phone,
            'service_point' => $point ? [
                'type' => $point->type,
                'label' => $point->label,
                'name' => $point->display_name,
                'section' => $point->section?->name,
                'subsection' => $point->subSection?->name,
            ] : null,
            'items_count' => (int) $order->items_count,
            'total_items_amount' => (float) ($order->total_items_amount ?? 0),
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'total_amount' => (float) ($order->total_amount ?? 0),
            'paid_amount' => (float) ($order->paid_amount ?? 0),
            'due_amount' => (float) ($order->due_amount ?? 0),
            'comment' => $order->comment,
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->item?->name ?? 'Menu item',
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) ($item->unit_price ?? 0),
                'total' => (float) ($item->total_amount ?? 0),
                'comment' => $item->comment,
                'options' => $item->options->map(fn ($option) => [
                    'name' => $option->name,
                    'price_adjustment' => (float) ($option->price_adjustment ?? 0),
                ])->values(),
            ])->values(),
            'history' => $order->statusHistories->map(fn ($entry) => [
                'from' => $entry->fromStatus?->name,
                'to' => $entry->toStatus?->name,
                'by' => $entry->changedBy?->name,
                'at' => $this->isoDate($entry->created_at),
                'note' => $entry->note,
            ])->values(),
            'approved_at' => $this->isoDate($order->approved_at),
            'created_at' => $this->isoDate($order->created_at),
            'updated_at' => $this->isoDate($order->updated_at),
        ];
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (string) $value;
    }

    private function servicePointData(ServicePoint $servicePoint): array
    {
        return [
            'uuid' => $servicePoint->uuid,
            'type' => $servicePoint->type,
            'label' => $servicePoint->label,
            'display_name' => $servicePoint->display_name,
            'capacity' => $servicePoint->capacity,
            'is_active' => (bool) $servicePoint->is_active,
            'qr_code' => $servicePoint->activeCode?->code,
            'ordering_channels' => $servicePoint->orderingChannels->map(fn ($channel) => [
                'slug' => $channel->slug,
                'name' => $channel->name,
            ])->values(),
            'updated_at' => $servicePoint->updated_at?->toIso8601String(),
        ];
    }

    private function teamMemberData(object $member): array
    {
        return [
            'uuid' => $member->uuid,
            'name' => $member->name,
            'code' => $member->code,
            'email' => $member->email,
            'phone' => $member->phone,
            'last_login_at' => $member->last_login_at,
            'last_login_portal' => $member->last_login_portal,
            'user_type' => $member->user_type,
            'source' => $member->source,
            'role' => $member->role,
            'title' => $member->title,
            'access_scope' => $member->access_scope,
            'is_primary' => (bool) $member->is_primary,
            'membership_active' => (bool) $member->membership_active,
            'user_active' => (bool) $member->user_active,
            'invited_at' => $member->invited_at,
            'accepted_at' => $member->accepted_at,
            'activated_at' => $member->activated_at,
            'deactivated_at' => $member->deactivated_at,
            'revoked_at' => $member->revoked_at,
        ];
    }

    private function documentData(ComplianceDocument $document): array
    {
        return [
            'uuid' => $document->uuid,
            'business' => $document->business ? [
                'uuid' => $document->business->uuid,
                'name' => $document->business->name,
                'vendor' => $document->business->vendor?->name,
                'country' => $document->business->district?->city?->country?->name,
            ] : null,
            'type' => $document->document_type,
            'label' => $document->document_type_name ?? $document->document_type,
            'document_number' => $document->document_number,
            'filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'file_size' => (int) $document->file_size,
            'status' => $document->status === 'approved' && $document->expires_at?->isPast() ? 'expired' : $document->status,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'uploaded_by' => $document->uploader?->name,
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'reviewed_by' => $document->reviewer?->name,
            'updated_at' => $document->updated_at?->toIso8601String(),
            'return_reason' => $document->rejection_reason,
            'rejection_reason' => $document->rejection_reason,
        ];
    }

    private function onboardingPaymentData($payment): ?array
    {
        if (! $payment) {
            return null;
        }

        return [
            'uuid' => $payment->uuid,
            'package_id' => $payment->package_id,
            'package' => $payment->package,
            'package_price' => $payment->onboardingPackage?->price,
            'amount_due' => $payment->amount_due,
            'amount_paid' => $payment->amount_paid,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
            'payment_reference' => $payment->payment_reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'proof_filename' => $payment->proof_filename,
            'verified_at' => $payment->verified_at?->toIso8601String(),
            'notes' => $payment->notes,
        ];
    }

    private function paymentSettingData($setting): array
    {
        return [
            'provider' => $setting->provider,
            'currency' => $setting->currency,
            'commission_rate' => $setting->commission_rate,
            'commission_basis' => $setting->commission_basis,
            'fee_bearer' => $setting->fee_bearer,
            'settlement_mode' => $setting->settlement_mode,
            'settlement_requirement' => $setting->settlement_requirement ?? 'required',
            'is_checkout_enabled' => (bool) $setting->is_checkout_enabled,
            'is_settlement_enabled' => (bool) $setting->is_settlement_enabled,
        ];
    }

    private function payoutAccountData($account): array
    {
        return [
            'uuid' => $account->uuid,
            'destination_type' => $account->destination_type,
            'provider' => $account->provider,
            'wallet_id' => $account->wallet_id,
            'account_holder_name' => $account->account_holder_name,
            'account_number' => $account->maskedAccountNumber(),
            'phone_number' => $account->phone_number,
            'currency' => $account->currency,
            'verification_status' => $account->verification_status,
            'status' => $account->status,
            'is_default' => (bool) $account->is_default,
            'verified_at' => $account->verified_at?->toIso8601String(),
            'rejection_reason' => $account->rejection_reason,
            'verification_document_filename' => $account->verification_document_filename,
            'verification_document_uploaded_at' => $account->verification_document_uploaded_at?->toIso8601String(),
        ];
    }

    private function documentRequirements(Business $business, $documents): array
    {
        $countryId = $business->district?->city?->country_id;
        $latestByType = $documents->keyBy('type');

        return ComplianceDocumentType::query()
            ->where('scope', 'business')
            ->where('is_active', true)
            ->with(['rules' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (ComplianceDocumentType $type) use ($business, $countryId, $latestByType): ?array {
                $rule = $type->rules
                    ->filter(fn ($rule) => ($rule->country_id === null || $rule->country_id === $countryId)
                        && ($rule->business_type_id === null || $rule->business_type_id === $business->business_type_id))
                    ->sortByDesc(fn ($rule) => (int) ($rule->country_id !== null) + (int) ($rule->business_type_id !== null))
                    ->first();

                if (! $rule) {
                    return null;
                }

                $document = $latestByType->get($type->key);

                return [
                    'key' => $type->key,
                    'name' => $type->name,
                    'description' => $type->description,
                    'required' => (bool) ($rule?->required_for_payment_activation ?? false),
                    'requires_expiry_date' => (bool) $type->requires_expiry_date,
                    'uploaded' => $document !== null,
                    'status' => $document['status'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
