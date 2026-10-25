<?php

namespace App\Services;

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Notifications\OnboardingAgentInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnboardingAgentService
{
    public function audit(string $action, ?int $agentId, ?int $vendorId = null, array $details = []): void
    {
        DB::table('onboarding_audits')->insert(['actor_id' => auth()->id(), 'agent_id' => $agentId, 'vendor_id' => $vendorId, 'action' => $action, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function assign(User $agent, Vendor $vendor, string $scope, array $businessIds): void
    {
        abort_unless($agent->type === 'agent', 422, 'Select an onboarding agent.');
        abort_unless(in_array($scope, ['all_businesses', 'selected_businesses'], true), 422, 'Invalid assignment scope.');
        abort_if($scope === 'selected_businesses' && ! $businessIds, 422, 'Select at least one business.');
        abort_unless($vendor->businesses()->whereIn('id', $businessIds)->count() === count(array_unique($businessIds)), 422, 'Every business must belong to this vendor.');
        DB::transaction(function () use ($agent, $vendor, $scope, $businessIds) {
            $agent->vendors()->syncWithoutDetaching([$vendor->id => ['assigned_by' => auth()->id(), 'is_primary' => false, 'role' => 'manager', 'access_scope' => $scope, 'is_active' => true, 'revoked_at' => null]]);
            $ids = $vendor->businesses()->pluck('id');
            DB::table('agent_business_assignments')->where('user_id', $agent->id)->whereIn('business_id', $ids)->delete();
            if ($scope === 'selected_businesses') {
                foreach ($businessIds as $id) {
                    $agent->businesses()->attach($id, ['is_active' => true, 'activated_at' => now()]);
                }
            }
            $this->audit('assignment.saved', $agent->id, $vendor->id, ['scope' => $scope, 'business_ids' => $businessIds]);
        });
    }

    public function invite(User $user): void
    {
        $token = Str::random(64);
        DB::transaction(function () use ($user, $token) {
            DB::table('agent_invitations')->where('user_id', $user->id)->whereNull('accepted_at')->delete();
            DB::table('agent_invitations')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(2), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('agent.invited', $user->id);
        });
        $url = rtrim(config('app.business_url'), '/').'/accept-agent-invitation?token='.$token;
        if (app()->environment('local')) {
            \Illuminate\Support\Facades\Log::build(['driver' => 'single', 'path' => storage_path('logs/agent-invitations.log'), 'level' => 'info'])->info('Onboarding agent activation invitation', [
                'email' => $user->email,
                'activation_url' => $url,
                'expires_at' => now()->addDays(2)->toIso8601String(),
            ]);
        }
        $user->notify(new OnboardingAgentInvitation($url));
    }

    public function authorizeRequest(Request $request): void
    {
        abort_unless($request->user()->can('onboarding.manage'), 403, 'Onboarding role is required.');
        $path = preg_replace('#^v1/#', '', $request->path());
        $user = $request->user();
        $allowed = preg_match('#^(me|logout|change-password|profile|notifications|onboarding-agent)(/|$)#', $path)
            || $path === 'users/password-reset'
            || preg_match('#^(vendors|businesses|business-types|business-staff-roles|timezones)(/|$)#', $path);
        abort_unless($allowed, 403, 'Onboarding agents cannot access this feature.');
        abort_if(preg_match('#/(orders|reports|mno-checkout)(/|$)#', $path), 403, 'Onboarding access does not include live orders or reports.');
        if (preg_match('#/staff(/|$)#', $path)) {
            abort_unless(preg_match('#^businesses/[^/]+/staff(?:/[^/]+(?:/reset-password)?)?$#', $path), 403, 'Only assigned business staff can be managed during onboarding.');
        }
        abort_if(preg_match('#/(review|default)(/|$)#', $path), 403, 'Operations approval is required.');
        $vendor = $request->route('vendor');
        if ($vendor) {
            $vendor = $vendor instanceof Vendor ? $vendor : Vendor::where('uuid', $vendor)->firstOrFail();
            $assignment = $user->vendors()->whereKey($vendor->id)->wherePivot('is_active', true)->first();
            abort_unless($assignment, 403, 'This vendor is not assigned to you.');
            if (! $request->isMethod('GET') && $request->is('v1/vendors/*') && ! $request->is('v1/vendors/*/documents*')) {
                abort_unless($assignment->pivot->access_scope === 'all_businesses', 403, 'A vendor-wide assignment is required.');
            }
        }
        $business = $request->route('business');
        if ($business) {
            $business = $business instanceof Business ? $business : Business::where('uuid', $business)->firstOrFail();
            $assignment = $user->vendors()->whereKey($business->vendor_id)->wherePivot('is_active', true)->first();
            abort_unless($assignment && ($assignment->pivot->access_scope === 'all_businesses' || $user->businesses()->whereKey($business->id)->wherePivot('is_active', true)->exists()), 403, 'This business is not assigned to you.');
            $request->attributes->set('onboarding_vendor_id', $business->vendor_id);
        }
    }
}
