<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\BusinessType;
use App\Models\Business\ComplianceDocument;
use App\Models\Location\City;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Models\Section\Section;
use App\Models\Section\ServicePoint;
use App\Services\Business\BusinessActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
                'payoutAccounts:id,uuid,business_id,destination_type,provider,account_number,account_holder_name,currency,verification_status,status,is_default,verified_at,rejection_reason,country_id',
                'payoutAccounts.country:id,name,iso2',
            ])
            ->withCount(['complianceDocuments as pending_documents_count' => fn ($query) => $query->whereIn('status', ['pending', 'in_review', 'needs_review'])])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $documents = $request->user()->can('operations.kyc.view')
            ? $business->complianceDocuments()->latest()->get()->map(fn (ComplianceDocument $document) => $this->documentData($document))
            : collect();
        $activation = $this->activation->status($business);

        return $this->sendResponse([
            'business' => $this->businessData($business),
            'documents' => $documents,
            'activation' => $activation,
        ], 'Operations business details retrieved successfully.');
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
            'status' => ['nullable', 'in:all,pending,in_review,approved,rejected,expired'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $documents = ComplianceDocument::query()
            ->whereNotNull('business_id')
            ->with(['business:id,uuid,name,vendor_id,district_id,is_active', 'business.vendor:id,name', 'business.district:id,name,city_id', 'business.district.city:id,name,country_id', 'business.district.city.country:id,name,iso2', 'uploader:id,name'])
            ->when(($filters['status'] ?? 'pending') !== 'all', fn ($query) => $query->where('status', $filters['status'] ?? 'pending'))
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
            ->orderByRaw("CASE WHEN status IN ('pending', 'in_review', 'needs_review') THEN 0 ELSE 1 END")
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
            'decision' => ['required', 'in:approved,rejected'],
            'rejection_reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:2000'],
        ]);

        $permission = $validated['decision'] === 'approved' ? 'operations.kyc.approve' : 'operations.kyc.reject';
        abort_unless($request->user()->can($permission), 403, 'You do not have permission to make this document decision.');

        $document = ComplianceDocument::query()
            ->where('uuid', $uuid)
            ->whereNotNull('business_id')
            ->firstOrFail();
        abort_unless(in_array($document->status, ['pending', 'in_review', 'needs_review'], true), 409, 'Only documents awaiting review can be decided.');

        $document->update([
            'status' => $validated['decision'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $validated['decision'] === 'rejected' ? trim($validated['rejection_reason']) : null,
        ]);

        return $this->sendResponse([
            'document' => $this->documentData($document->fresh()->load(['business:id,uuid,name', 'uploader:id,name'])),
        ], 'Compliance document decision recorded.');
    }

    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $permission = $validated['is_active'] ? 'operations.businesses.approve' : 'operations.businesses.suspend';
        abort_unless($request->user()->can($permission), 403, 'You do not have permission to change this business status.');

        $business = Business::query()->where('uuid', $uuid)->firstOrFail();
        $business->update(['is_active' => $validated['is_active']]);

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
            'timezone' => $business->timezoneDefinition?->identifier ?: $business->timezone,
            'district' => $business->district?->name,
            'city' => $business->district?->city?->name,
            'country' => $business->district?->city?->country?->name,
            'country_iso2' => $business->district?->city?->country?->iso2,
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
                'settlement_mode' => $business->paymentSetting->settlement_mode,
                'is_checkout_enabled' => (bool) $business->paymentSetting->is_checkout_enabled,
                'is_settlement_enabled' => (bool) $business->paymentSetting->is_settlement_enabled,
            ] : null,
            'payout_accounts' => $business->relationLoaded('payoutAccounts') ? $business->payoutAccounts->map(fn ($account) => [
                'uuid' => $account->uuid,
                'destination_type' => $account->destination_type,
                'provider' => $account->provider,
                'account_holder_name' => $account->account_holder_name,
                'account_number' => $account->maskedAccountNumber(),
                'currency' => $account->currency,
                'verification_status' => $account->verification_status,
                'status' => $account->status,
                'is_default' => (bool) $account->is_default,
                'verified_at' => $account->verified_at?->toIso8601String(),
                'rejection_reason' => $account->rejection_reason,
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
            'status' => $document->status,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'uploaded_by' => $document->uploader?->name,
            'reviewed_at' => $document->reviewed_at?->toIso8601String(),
            'rejection_reason' => $document->rejection_reason,
        ];
    }
}
