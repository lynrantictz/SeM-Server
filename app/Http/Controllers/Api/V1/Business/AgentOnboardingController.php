<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Models\Business\Vendor;
use App\Models\Business\VendorInvitation;
use App\Notifications\VendorInvitation as InvitationMail;
use App\Services\OnboardingAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentOnboardingController extends BaseController
{
    private function agent(): User
    {
        $u = auth()->user();
        abort_unless($u->type === 'agent' && $u->is_active, 403, 'Active agent account required.');

        return $u;
    }

    public function index(Request $request)
    {
        $u = $this->agent();
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100']]);
        $paginator = $u->vendors()->wherePivot('is_active', true)->withPivot(['stage', 'notes'])->with('country')
            ->when($filters['search'] ?? null, function ($query, $term) use ($u) {
                $pattern = '%'.mb_strtolower(trim($term)).'%';
                $query->where(function ($match) use ($u, $pattern) {
                    $match->whereRaw('LOWER(vendors.name) LIKE ?', [$pattern])->orWhereHas('businesses', function ($businesses) use ($u, $pattern) {
                        $businesses->whereRaw('LOWER(businesses.name) LIKE ?', [$pattern])->where(function ($scope) use ($u) {
                            $scope->where('agent_vendor_assignments.access_scope', 'all_businesses')->orWhereIn('businesses.id', $u->businesses()->wherePivot('is_active', true)->select('businesses.id'));
                        });
                    });
                });
            })->orderBy('vendors.name')->orderBy('vendors.id')->paginate($filters['per_page'] ?? 10)->withQueryString();
        $vendors = $paginator->getCollection()->map(fn ($v) => [
            'uuid' => $v->uuid, 'name' => $v->name, 'scope' => $v->pivot->access_scope, 'stage' => $v->pivot->stage, 'notes' => $v->pivot->notes,
            'owner' => $v->users()->wherePivot('is_primary', true)->first(['users.name', 'users.email']),
            'owner_invited' => VendorInvitation::where('vendor_id', $v->id)->where('assign_owner', true)->whereNull('accepted_at')->where('expires_at', '>', now())->exists(),
            'businesses' => $v->businesses()->when($v->pivot->access_scope !== 'all_businesses', fn ($q) => $q->whereIn('id', $u->businesses()->wherePivot('is_active', true)->select('businesses.id')))->get(['id', 'uuid', 'name']),
        ]);

        return $this->sendResponse(['vendors' => $vendors, 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]], 'Assigned onboarding workspace');
    }

    private function assigned(string $uuid, bool $vendorWide = false): Vendor
    {
        return $this->agent()->vendors()->where('vendors.uuid', $uuid)->wherePivot('is_active', true)->when($vendorWide, fn ($q) => $q->where('agent_vendor_assignments.access_scope', 'all_businesses'))->firstOrFail();
    }

    public function progress(Request $r, string $uuid, OnboardingAgentService $service)
    {
        $u = $this->agent();
        $v = $this->assigned($uuid);
        $d = $r->validate(['stage' => ['required', 'in:draft,in_progress,submitted,ready,handed_over'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $previous = DB::table('agent_vendor_assignments')->where('user_id', $u->id)->where('vendor_id', $v->id)->value('stage');
        abort_unless(in_array($d['stage'], ['draft', 'in_progress', 'submitted'], true) || ($d['stage'] === 'ready' && $previous === 'ready') || ($d['stage'] === 'handed_over' && in_array($previous, ['ready', 'handed_over'], true)), 403, 'Operations must mark the onboarding ready before handover.');
        if ($d['stage'] === 'handed_over') {
            abort_unless($v->users()->wherePivot('is_primary', true)->wherePivot('is_active', true)->exists(), 422, 'An owner must accept their invitation before handover.');
        }
        $u->vendors()->updateExistingPivot($v->id, $d);
        $service->audit('onboarding.progress', $u->id, $v->id, ['previous' => $previous] + $d);

        return $this->sendResponse([], 'Progress updated');
    }

    public function owner(Request $r, string $uuid, OnboardingAgentService $service)
    {
        $u = $this->agent();
        $v = $this->assigned($uuid, true);
        $d = $r->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim($d['email']));
        $result = DB::transaction(function () use ($v, $d, $email) {
            Vendor::whereKey($v->id)->lockForUpdate()->first();
            abort_if($v->users()->wherePivot('is_primary', true)->exists(), 409, 'This vendor already has an owner. Operations must handle ownership transfers.');
            $owner = User::whereRaw('LOWER(email) = ?', [$email])->first();
            abort_if($owner && $owner->type !== 'owner', 422, 'This email belongs to another account type.');
            abort_if($owner && ! $owner->is_active && $owner->email_verified_at, 422, 'This owner account is disabled. Contact Operations.');
            $setup = ! $owner;
            if (! $owner) {
                $owner = new User(['name' => $d['name'], 'email' => $email, 'type' => 'owner', 'password' => Str::random(64), 'is_active' => false]);
                $owner->forceFill(['uuid' => (string) Str::uuid()])->saveQuietly();
            }
            $owner->assignRole('owner');
            $v->users()->syncWithoutDetaching([$owner->id => ['is_primary' => false, 'role' => 'manager', 'access_scope' => 'all_businesses', 'is_active' => false, 'invited_at' => now()]]);
            VendorInvitation::where('vendor_id', $v->id)->where('assign_owner', true)->whereNull('accepted_at')->delete();
            $token = Str::random(64);
            $invitation = VendorInvitation::create(['vendor_id' => $v->id, 'user_id' => $owner->id, 'token' => hash('sha256', $token), 'requires_password_setup' => $setup || ! $owner->email_verified_at, 'assign_owner' => true, 'expires_at' => now()->addDays(2)]);

            return [$owner, $token, $invitation];
        });
        [$owner,$token,$invitation] = $result;
        $owner->notify(new InvitationMail($v, rtrim(config('app.business_url'), '/').'/accept-vendor-invitation?token='.$token, $invitation->requires_password_setup, $invitation->id));
        $service->audit('owner.invited', $u->id, $v->id, ['owner_uuid' => $owner->uuid]);

        return $this->sendResponse([],'Owner invitation queued. Ownership activates only after acceptance.');
    }
}
