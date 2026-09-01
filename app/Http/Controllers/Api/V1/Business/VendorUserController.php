<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Enums\User\UserType;
use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Models\Business\Vendor;
use App\Models\Business\VendorInvitation;
use App\Notifications\VendorInvitation as VendorInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VendorUserController extends BaseController
{
    private function ensureAccess(Vendor $vendor): void
    {
        $user = auth()->user();
        $access = $user->vendors()->whereKey($vendor->id);

        if ($user->type === UserType::VENDOR->value) {
            $access->wherePivot('is_active', true);
        }

        abort_unless($access->exists(), HTTP_FORBIDDEN, 'You do not have access to this vendor.');
    }

    private function ensureOwner(Vendor $vendor): void
    {
        $membership = auth()->user()->vendors()->whereKey($vendor->id)->first();
        abort_unless($membership && $membership->pivot->is_primary, HTTP_FORBIDDEN, 'Only this vendor owner can manage vendor users.');
    }

    public function index(Request $request, Vendor $vendor)
    {
        $this->ensureOwner($vendor);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = $validated['per_page'] ?? 10;

        $users = $vendor->users()
            ->select(['users.id', 'users.uuid', 'users.name', 'users.email', 'users.is_active'])
            ->with(['businesses' => fn ($query) => $query
                ->select(['businesses.id', 'businesses.uuid', 'businesses.name'])
                ->where('businesses.vendor_id', $vendor->id)])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('users.name', 'ilike', "%{$search}%")
                    ->orWhere('users.email', 'ilike', "%{$search}%");
            }))
            ->orderBy('users.name')
            ->paginate($perPage)
            ->withQueryString();

        $data['users'] = [
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
            'links' => [
                'first' => $users->url(1),
                'last' => $users->url($users->lastPage()),
                'prev' => $users->previousPageUrl(),
                'next' => $users->nextPageUrl(),
            ],
        ];

        return $this->sendResponse($data, 'Vendor users retrieved successfully.');
    }

    public function store(Request $request, Vendor $vendor)
    {
        $this->ensureOwner($vendor);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:manager,viewer'],
            'access_scope' => ['required', 'in:all_businesses,selected_businesses'],
            'business_uuids' => ['array'],
            'business_uuids.*' => ['uuid'],
        ]);

        if ($validated['access_scope'] === 'selected_businesses' && empty($validated['business_uuids'])) {
            return $this->sendError('Select at least one business for limited access.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $businessIds = $vendor->businesses()
            ->whereIn('uuid', $validated['business_uuids'] ?? [])
            ->pluck('id');
        if ($businessIds->count() !== count($validated['business_uuids'] ?? [])) {
            return $this->sendError('One or more selected businesses do not belong to this vendor.', [], HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = DB::transaction(function () use ($vendor, $validated, $businessIds) {
            $user = User::where('email', strtolower($validated['email']))->first();
            $requiresPasswordSetup = !$user || !$user->is_active || !$user->email_verified_at;
            $user ??= User::create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => Str::password(32),
                'type' => UserType::VENDOR->value,
                'is_active' => false,
            ]);

            $vendor->users()->syncWithoutDetaching([
                $user->id => [
                    'role' => $validated['role'],
                    'access_scope' => $validated['access_scope'],
                    'is_active' => false,
                    'invited_at' => now(),
                    'accepted_at' => null,
                    'revoked_at' => null,
                ],
            ]);

            $user->businesses()->detach($vendor->businesses()->pluck('id'));
            if ($validated['access_scope'] === 'selected_businesses') {
                $assignments = $businessIds->mapWithKeys(fn ($businessId) => [
                    $businessId => [
                        'title' => "vendor-{$vendor->id}-user-{$user->id}-business-{$businessId}",
                        'business_role' => 'vendor_manager',
                        'is_active' => true,
                        'activated_at' => now(),
                    ],
                ])->all();
                $user->businesses()->syncWithoutDetaching($assignments);
            }

            VendorInvitation::where('vendor_id', $vendor->id)->where('user_id', $user->id)->whereNull('accepted_at')->delete();
            $token = Str::random(64);
            $invitation = VendorInvitation::create([
                'vendor_id' => $vendor->id,
                'user_id' => $user->id,
                'token' => hash('sha256', $token),
                'requires_password_setup' => $requiresPasswordSetup,
                'expires_at' => now()->addDays(7),
            ]);

            return [$user, $token, $requiresPasswordSetup, $invitation->id];
        });

        [$user, $token, $requiresPasswordSetup, $invitationId] = $result;
        $acceptUrl = rtrim(config('app.business_url'), '/') . '/accept-vendor-invitation?token=' . $token;
        $user->notify(new VendorInvitationNotification($vendor, $acceptUrl, $requiresPasswordSetup, $invitationId));

        return $this->sendResponse([], 'Vendor user invited successfully.', HTTP_CREATED);
    }

    public function update(Request $request, Vendor $vendor, User $user)
    {
        $this->ensureOwner($vendor);
        abort_unless($vendor->users()->whereKey($user->id)->exists(), HTTP_NOT_FOUND, 'Vendor user not found.');
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($vendor, $user, $validated) {
            $user->update(array_filter([
                'name' => $validated['name'] ?? null,
                'email' => isset($validated['email']) ? strtolower($validated['email']) : null,
            ], fn ($value) => $value !== null));

            if (array_key_exists('is_active', $validated)) {
                $vendor->users()->updateExistingPivot($user->id, [
                    'is_active' => $validated['is_active'],
                    'revoked_at' => $validated['is_active'] ? null : now(),
                ]);
            }
        });

        return $this->sendResponse([], 'Vendor user updated successfully.');
    }

    public function resendInvitation(Vendor $vendor, User $user)
    {
        $this->ensureOwner($vendor);
        abort_unless($vendor->users()->whereKey($user->id)->exists(), HTTP_NOT_FOUND, 'Vendor user not found.');
        abort_if(
            VendorInvitation::where('vendor_id', $vendor->id)
                ->where('user_id', $user->id)
                ->whereNotNull('accepted_at')
                ->exists(),
            HTTP_UNPROCESSABLE_ENTITY,
            'This user has already accepted their invitation and cannot be invited again.'
        );

        $token = Str::random(64);
        $requiresPasswordSetup = !$user->is_active || !$user->email_verified_at;
        VendorInvitation::where('vendor_id', $vendor->id)->where('user_id', $user->id)->whereNull('accepted_at')->delete();
        $invitation = VendorInvitation::create([
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'token' => hash('sha256', $token),
            'requires_password_setup' => $requiresPasswordSetup,
            'expires_at' => now()->addDays(7),
        ]);
        $vendor->users()->updateExistingPivot($user->id, ['invited_at' => now(), 'is_active' => false, 'accepted_at' => null, 'revoked_at' => null]);

        $acceptUrl = rtrim(config('app.business_url'), '/') . '/accept-vendor-invitation?token=' . $token;
        $user->notify(new VendorInvitationNotification($vendor, $acceptUrl, $requiresPasswordSetup, $invitation->id));

        return $this->sendResponse([], 'A new invitation email has been sent.');
    }

    public function acceptInvitation(Request $request)
    {
        $validated = $request->validate(['token' => ['required', 'string']]);
        $invitation = $this->pendingInvitation($validated['token']);

        if ($invitation->requires_password_setup) {
            $request->validate(['password' => ['required', 'confirmed', 'min:8']]);
        }

        DB::transaction(function () use ($invitation, $request) {
            if ($invitation->requires_password_setup) {
                $invitation->user->update([
                    'password' => $request->input('password'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
            }
            $invitation->vendor->users()->updateExistingPivot($invitation->user_id, ['is_active' => true, 'accepted_at' => now(), 'revoked_at' => null]);
            $invitation->update(['accepted_at' => now()]);
        });

        return $this->sendResponse([], $invitation->requires_password_setup
            ? 'Invitation accepted. You can now sign in with your new password.'
            : 'Invitation accepted. Your existing Paperstick account now has access.');
    }

    public function showInvitation(Request $request)
    {
        $validated = $request->validate(['token' => ['required', 'string']]);
        $invitation = $this->pendingInvitation($validated['token']);

        return $this->sendResponse([
            'invitation' => [
                'email' => $invitation->user->email,
                'vendor_name' => $invitation->vendor->name,
                'requires_password_setup' => $invitation->requires_password_setup,
            ],
        ], 'Invitation retrieved successfully.');
    }

    public function acceptInApp(Request $request, VendorInvitation $invitation)
    {
        abort_unless($invitation->user_id === $request->user()->id, HTTP_FORBIDDEN, 'This invitation does not belong to you.');
        abort_if($invitation->requires_password_setup, HTTP_UNPROCESSABLE_ENTITY, 'Set your password from the invitation email to activate this new account.');
        abort_if($invitation->accepted_at || $invitation->expires_at->isPast(), HTTP_UNPROCESSABLE_ENTITY, 'This invitation is no longer available.');

        DB::transaction(function () use ($invitation) {
            $invitation->vendor->users()->updateExistingPivot($invitation->user_id, ['is_active' => true, 'accepted_at' => now(), 'revoked_at' => null]);
            $invitation->update(['accepted_at' => now()]);
        });

        return $this->sendResponse([], 'Vendor access accepted successfully.');
    }

    private function pendingInvitation(string $token): VendorInvitation
    {
        $invitation = VendorInvitation::query()->with(['user', 'vendor'])
            ->where('token', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->first();

        if (!$invitation || $invitation->expires_at->isPast()) {
            abort(HTTP_UNPROCESSABLE_ENTITY, 'This invitation is invalid or has expired.');
        }

        return $invitation;
    }
}
