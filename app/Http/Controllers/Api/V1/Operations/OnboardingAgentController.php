<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Models\Business\Vendor;
use App\Services\OnboardingAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnboardingAgentController extends BaseController
{
    private function check(string $action): void
    {
        abort_unless(auth()->user()->type === 'paperstic' && auth()->user()->is_active && auth()->user()->can('operations.agents.'.$action), 403, 'Operations agent administration permission is required.');
    }

    private function agent(string $uuid): User
    {
        return User::where('type', 'agent')->where('uuid', $uuid)->firstOrFail();
    }

    private function phoneRules(): array
    {
        return ['nullable', 'string', 'max:24', function ($attribute, $value, $fail) {
            try {
                app(\App\Services\PhoneNumberNormalizer::class)->normalize($value);
            } catch (\App\Exceptions\InvalidPhoneNumberException $exception) {
                $fail('Enter a valid phone number starting with its country code. '.$exception->getMessage());
            }
        }];
    }

    private function data(User $user): array
    {
        return ['uuid' => $user->uuid, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'active' => (bool) $user->is_active, 'accepted' => $user->email_verified_at !== null, 'last_login_at' => $user->last_login_at,
            'assignments' => $user->vendors()->with('businesses:id,uuid,vendor_id,name')->get()->map(fn ($v) => ['uuid' => $v->uuid, 'name' => $v->name, 'active' => (bool) $v->pivot->is_active, 'scope' => $v->pivot->access_scope, 'stage' => DB::table('agent_vendor_assignments')->where('user_id', $user->id)->where('vendor_id', $v->id)->value('stage'), 'businesses' => $v->businesses->filter(fn ($b) => $v->pivot->access_scope === 'all_businesses' || $user->businesses()->whereKey($b->id)->wherePivot('is_active', true)->exists())->map(fn ($b) => ['uuid' => $b->uuid, 'name' => $b->name])->values()])];
    }

    public function index(Request $r)
    {
        $this->check('view');
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:10,15,25,50,100']]);
        $q = User::where('type', 'agent')->when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($s).'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($s).'%'])))->orderBy('name')->orderBy('id')->paginate($d['per_page'] ?? 10)->withQueryString();

        return $this->sendResponse(['agents' => $q->getCollection()->map(fn ($u) => $this->data($u)), 'page' => $q->currentPage(), 'last_page' => $q->lastPage(), 'total' => $q->total(), 'per_page' => $q->perPage(), 'from' => $q->firstItem(), 'to' => $q->lastItem()], 'Onboarding agents');
    }

    public function businessAgents(string $uuid)
    {
        $this->check('view');
        $business = \App\Models\Business\Business::where('uuid', $uuid)->firstOrFail();
        $users = User::where('type', 'agent')->whereExists(function ($q) use ($business) {
            $q->selectRaw('1')->from('agent_vendor_assignments as a')->whereColumn('a.user_id', 'users.id')->where('a.vendor_id', $business->vendor_id)->where('a.is_active', true)->where(function ($q) use ($business) {
                $q->where('a.access_scope', 'all_businesses')->orWhereExists(function ($q) use ($business) {
                    $q->selectRaw('1')->from('agent_business_assignments as b')->whereColumn('b.user_id', 'users.id')->where('b.business_id', $business->id)->where('b.is_active', true);
                });
            });
        })->get();

        return $this->sendResponse(['agents' => $users->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->name, 'active' => (bool) $u->is_active])->values()], 'Assigned onboarding agents');
    }

    public function options()
    {
        $this->check('assign');

        return $this->sendResponse(['vendors' => Vendor::with('businesses:id,uuid,vendor_id,name')->orderBy('name')->get(['id', 'uuid', 'name'])->map(fn ($v) => ['uuid' => $v->uuid, 'name' => $v->name, 'businesses' => $v->businesses->map(fn ($b) => ['uuid' => $b->uuid, 'name' => $b->name])])], 'Assignment options');
    }

    public function store(Request $r, OnboardingAgentService $service)
    {
        $this->check('create');
        $r->merge(['email' => mb_strtolower(trim((string) $r->email))]);
        $d = $r->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'phone' => $this->phoneRules()]);
        $user = DB::transaction(function () use ($d) {
            $u = User::create($d + ['type' => 'agent', 'password' => Str::random(64), 'is_active' => true]);
            $u->assignRole('onboarding-agent');

            return $u;
        });
        $service->invite($user);

        return $this->sendResponse(['agent' => $this->data($user)], 'Agent created. Password invitation queued.', 201);
    }

    public function show(string $uuid)
    {
        $this->check('view');
        $u = $this->agent($uuid);

        return $this->sendResponse(['agent' => $this->data($u), 'activity' => DB::table('onboarding_audits')->leftJoin('users', 'users.id', '=', 'onboarding_audits.actor_id')->where('agent_id', $u->id)->orderByDesc('onboarding_audits.id')->limit(100)->get(['onboarding_audits.action', 'onboarding_audits.details', 'onboarding_audits.created_at', 'users.name as actor'])], 'Agent details');
    }

    public function update(Request $r, string $uuid, OnboardingAgentService $service)
    {
        $this->check('update');
        $u = $this->agent($uuid);
        $d = $r->validate(['name' => ['required', 'string', 'max:255'], 'phone' => $this->phoneRules()]);
        $u->update($d);
        $service->audit('agent.updated', $u->id, null, $d);

        return $this->sendResponse(['agent' => $this->data($u)], 'Agent updated');
    }

    public function status(Request $r, string $uuid, OnboardingAgentService $service)
    {
        $this->check('disable');
        $d = $r->validate(['active' => ['required', 'boolean']]);
        $u = $this->agent($uuid);
        DB::transaction(function () use ($u, $d, $service) {
            $u->update(['is_active' => $d['active']]);
            $u->tokens()->delete();
            $service->audit($d['active'] ? 'agent.enabled' : 'agent.disabled', $u->id);
        });

        return $this->sendResponse([], 'Agent access updated. Existing sessions revoked.');
    }

    public function invite(string $uuid, OnboardingAgentService $service)
    {
        $this->check('create');
        $u = $this->agent($uuid);
        abort_unless($u->is_active && ! $u->email_verified_at, 422, 'Only active agents awaiting acceptance can be invited.');
        $service->invite($u);

        return $this->sendResponse([], 'Invitation queued');
    }

    public function assign(Request $r, string $uuid, OnboardingAgentService $service)
    {
        $this->check('assign');
        $u = $this->agent($uuid);
        $d = $r->validate(['vendor_uuid' => ['required', 'uuid'], 'scope' => ['required', 'in:all_businesses,selected_businesses'], 'business_uuids' => ['array'], 'business_uuids.*' => ['uuid']]);
        $v = Vendor::where('uuid', $d['vendor_uuid'])->firstOrFail();
        $uuids = array_unique($d['business_uuids'] ?? []);
        $ids = $v->businesses()->whereIn('uuid', $uuids)->pluck('id')->all();
        abort_unless(count($ids) === count($uuids), 422, 'Invalid business assignment.');
        $service->assign($u, $v, $d['scope'], $ids);

        return $this->sendResponse([], 'Assignment saved');
    }

    public function progress(Request $r, string $uuid, string $vendorUuid, OnboardingAgentService $service)
    {
        $this->check('assign');
        $u = $this->agent($uuid);
        $v = Vendor::where('uuid', $vendorUuid)->firstOrFail();
        $d = $r->validate(['stage' => ['required', 'in:draft,in_progress,submitted,ready,handed_over'], 'notes' => ['nullable', 'string', 'max:2000']]);
        abort_unless($u->vendors()->whereKey($v->id)->wherePivot('is_active', true)->exists(), 422, 'No active assignment.');
        if ($d['stage'] === 'handed_over') {
            abort_unless($v->users()->wherePivot('is_primary', true)->wherePivot('is_active', true)->exists(), 422, 'The owner must accept before handover.');
        }
        $u->vendors()->updateExistingPivot($v->id, $d);
        $service->audit('onboarding.reviewed', $u->id, $v->id, $d);

        return $this->sendResponse([], 'Progress updated');
    }

    public function unassign(string $uuid, string $vendorUuid, OnboardingAgentService $service)
    {
        $this->check('assign');
        $u = $this->agent($uuid);
        $v = Vendor::where('uuid', $vendorUuid)->firstOrFail();
        DB::transaction(function () use ($u, $v, $service) {
            $u->vendors()->updateExistingPivot($v->id, ['is_active' => false, 'revoked_at' => now()]);
            DB::table('agent_business_assignments')->where('user_id', $u->id)->whereIn('business_id', $v->businesses()->pluck('id'))->delete();
            $service->audit('assignment.removed', $u->id, $v->id);
        });

        return $this->sendResponse([], 'Assignment removed');
    }
}
