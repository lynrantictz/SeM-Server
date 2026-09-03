<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessStaffRole;
use App\Models\Business\BusinessUser;
use App\Models\Location\District;
use App\Services\BusinessStaffService;
use App\Services\PhoneNumberNormalizer;
use Illuminate\Http\Request;

class BusinessStaffController extends BaseController
{
    public function index(Request $request, Business $business): mixed
    {
        $this->ensureCanViewBusiness($business);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $staff = BusinessUser::query()
            ->where('business_id', $business->id)
            ->whereHas('user', fn ($query) => $query->where('type', 'business'))
            ->with(['user:id,uuid,name,phone,code,type,is_active,created_at', 'businessStaffRole:id,uuid,name,slug'])
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where(function ($userQuery) use ($search) {
                        $userQuery->where('name', 'ilike', "%{$search}%")
                            ->orWhere('code', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%");
                    });
                });
            })
            ->orderByDesc('is_active')
            ->orderBy('business_role')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        return $this->sendResponse([
            'staff' => [
                'data' => collect($staff->items())->map(fn (BusinessUser $membership) => $this->staffData($membership))->values(),
                'meta' => [
                    'current_page' => $staff->currentPage(),
                    'last_page' => $staff->lastPage(),
                    'per_page' => $staff->perPage(),
                    'total' => $staff->total(),
                    'from' => $staff->firstItem(),
                    'to' => $staff->lastItem(),
                ],
                'links' => [
                    'first' => $staff->url(1),
                    'last' => $staff->url($staff->lastPage()),
                    'prev' => $staff->previousPageUrl(),
                    'next' => $staff->nextPageUrl(),
                ],
            ],
        ], 'Business staff retrieved successfully.');
    }

    public function store(Request $request, Business $business, BusinessStaffService $staff): mixed
    {
        $this->ensureCanManageBusiness($business);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'title' => ['nullable', 'string', 'max:100'],
            'business_staff_role_id' => ['required', 'integer'],
        ]);

        $role = BusinessStaffRole::query()
            ->whereKey($validated['business_staff_role_id'])
            ->where('is_active', true)
            ->firstOrFail();
        $validated['business_role'] = $role->slug;

        if (!empty($validated['phone'])) {
            $district = District::query()->with('city.country')->findOrFail($business->district_id);
            $validated['phone'] = (new PhoneNumberNormalizer())->normalize(
                $validated['phone'],
                $district->city->country->iso2,
            );
        }

        $user = $staff->create($business, $validated);

        return $this->sendResponse([
            'staff' => array_merge($this->staffData($user->businessUser()->where('business_id', $business->id)->with('user')->firstOrFail()), [
                'temporary_password' => $user->code,
            ]),
        ], 'Staff account created. Give the staff member this code as their temporary password.', 201);
    }

    public function managementAccess(Request $request, Business $business): mixed
    {
        $this->ensureCanViewBusiness($business);
        $business->load('vendor.country');
        $assignedManagerIds = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('business_role', 'vendor_manager')
            ->pluck('user_id');

        $people = $business->vendor->users()
            ->select(['users.id', 'users.uuid', 'users.name', 'users.email', 'users.phone', 'users.is_active'])
            ->get()
            ->filter(function ($user) use ($assignedManagerIds) {
                return $user->pivot->is_primary
                    || ($user->pivot->role === 'manager'
                        && ($user->pivot->access_scope === 'all_businesses' || $assignedManagerIds->contains($user->id)));
            })
            ->map(fn ($user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'access_role' => $user->pivot->is_primary ? 'Owner' : 'Vendor manager',
                'is_active' => (bool) $user->pivot->is_active,
                'registered_at' => $user->pivot->created_at,
                'activated_at' => $user->pivot->accepted_at,
                'deactivated_at' => $user->pivot->revoked_at,
            ])->values();

        return $this->sendResponse([
            'company' => [
                'name' => $business->vendor->name,
                'email' => $business->vendor->email,
                'phone' => $business->vendor->phone,
                'address' => $business->vendor->address,
                'country' => $business->vendor->country?->name,
                'registered_at' => $business->vendor->created_at,
            ],
            'people' => $people,
        ], 'Business management access retrieved successfully.');
    }

    public function update(Request $request, Business $business, User $user): mixed
    {
        $this->ensureCanManageBusiness($business);
        $membership = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'title' => ['nullable', 'string', 'max:100'],
            'business_staff_role_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('phone', $validated) && !empty($validated['phone'])) {
            $district = District::query()->with('city.country')->findOrFail($business->district_id);
            $validated['phone'] = (new PhoneNumberNormalizer())->normalize(
                $validated['phone'],
                $district->city->country->iso2,
            );
        }

        if ($user->type === 'business') {
            $user->update(array_filter([
                'name' => $validated['name'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ], fn ($value) => $value !== null));
        }

        $membershipUpdates = array_filter([
            'title' => $validated['title'] ?? null,
            'business_staff_role_id' => $validated['business_staff_role_id'] ?? null,
            'is_active' => $validated['is_active'] ?? null,
        ], fn ($value) => $value !== null);

        if (array_key_exists('business_staff_role_id', $validated)) {
            $role = BusinessStaffRole::query()
                ->whereKey($validated['business_staff_role_id'])
                ->where('is_active', true)
                ->firstOrFail();
            $membershipUpdates['business_role'] = $role->slug;
        }

        if (($validated['is_active'] ?? null) === true) {
            $membershipUpdates['activated_at'] = now();
            $membershipUpdates['deactivated_at'] = null;
        } elseif (($validated['is_active'] ?? null) === false) {
            $membershipUpdates['deactivated_at'] = now();
        }

        $membership->update($membershipUpdates);

        if ($user->type === 'business' && array_key_exists('is_active', $validated)) {
            $user->update(['is_active' => (bool) $validated['is_active']]);

            if (!$validated['is_active']) {
                $user->tokens()->delete();
            }
        }

        return $this->sendResponse([
            'staff' => $this->staffData($membership->fresh()->load(['user', 'businessStaffRole'])),
        ], 'Staff account updated successfully.');
    }

    public function resetPassword(Business $business, User $user): mixed
    {
        $this->ensureCanManageBusiness($business);

        $membership = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        abort_unless(
            $user->type === 'business' && filled($user->code),
            HTTP_UNPROCESSABLE_ENTITY,
            'Only staff accounts with a login code can have their password reset here.'
        );

        // The user mutator hashes the raw password. Do not hash the code here,
        // otherwise the value would be hashed twice and staff could not log in.
        $user->forceFill([
            'password' => $user->code,
            'must_change_password' => true,
        ])->save();

        // A reset invalidates any current device session. The next login will
        // use the code as the temporary password and open change-password.
        $user->tokens()->delete();

        return $this->sendResponse([
            'staff' => $this->staffData($membership->fresh()->load(['user', 'businessStaffRole'])),
        ], 'Password reset successfully. The staff login code is now their temporary password.');
    }

    private function ensureCanManageBusiness(Business $business): void
    {
        $staffMembership = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        if (auth()->user()->type === 'business') {
            abort_unless(
                $staffMembership?->business_role === 'business_manager',
                HTTP_FORBIDDEN,
                'Only the assigned business manager can manage staff.'
            );

            return;
        }

        $membership = auth()->user()->vendors()->whereKey($business->vendor_id)->first();
        abort_unless($membership, HTTP_FORBIDDEN, 'You do not have access to this business.');

        if ($membership->pivot->is_primary) {
            return;
        }

        abort_unless(
            $membership->pivot->is_active && $membership->pivot->role === 'manager',
            HTTP_FORBIDDEN,
            'You do not have permission to manage staff.'
        );

        if ($membership->pivot->access_scope === 'all_businesses') {
            return;
        }

        abort_unless(
            auth()->user()->businesses()
                ->whereKey($business->id)
                ->wherePivot('is_active', true)
                ->exists(),
            HTTP_FORBIDDEN,
            'You only have access to selected businesses.'
        );
    }

    private function ensureCanViewBusiness(Business $business): void
    {
        if (auth()->user()->type === 'business') {
            abort_unless(
                BusinessUser::query()
                    ->where('business_id', $business->id)
                    ->where('user_id', auth()->id())
                    ->where('is_active', true)
                    ->exists(),
                HTTP_FORBIDDEN,
                'You do not have access to this business.'
            );

            return;
        }

        $membership = auth()->user()->vendors()->whereKey($business->vendor_id)->first();
        if ($membership?->pivot->is_primary || $membership?->pivot->access_scope === 'all_businesses') {
            return;
        }

        abort_unless(
            $membership?->pivot->is_active && auth()->user()->businesses()
                ->whereKey($business->id)
                ->wherePivot('is_active', true)
                ->exists(),
            HTTP_FORBIDDEN,
            'You do not have access to this business.'
        );
    }

    private function staffData(BusinessUser $membership): array
    {
        $user = $membership->user;

        return [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'phone' => $user->phone,
            'code' => $user->code,
            'user_type' => $user->type,
            'title' => $membership->title,
            'business_role' => $membership->business_role,
            'business_staff_role' => $membership->businessStaffRole ? [
                'id' => $membership->businessStaffRole->id,
                'uuid' => $membership->businessStaffRole->uuid,
                'name' => $membership->businessStaffRole->name,
                'slug' => $membership->businessStaffRole->slug,
            ] : null,
            'is_active' => (bool) $membership->is_active,
            // A staff account is registered when the user account is created.
            // Older business_user rows may not have timestamps because they
            // were attached before this relationship started writing them.
            'registered_at' => $user->created_at,
            'activated_at' => $membership->activated_at,
            'deactivated_at' => $membership->deactivated_at,
        ];
    }
}
