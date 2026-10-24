<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\BaseController;
use App\Models\Auth\User;
use App\Services\OnboardingAgentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentInvitationController extends BaseController
{
    private function invitation(Request $r)
    {
        $d = $r->validate(['token' => ['required', 'string', 'max:128']]);
        $i = DB::table('agent_invitations')->where('token_hash', hash('sha256', $d['token']))->whereNull('accepted_at')->where('expires_at', '>', now())->first();
        abort_unless($i, 422, 'This invitation is invalid or expired.');
        $u = User::findOrFail($i->user_id);
        abort_unless($u->type === 'agent' && $u->is_active, 403, 'Agent access is disabled.');

        return [$i, $u];
    }

    public function show(Request $r)
    {
        [$i,$u] = $this->invitation($r);

        return $this->sendResponse(['name' => $u->name, 'email' => $u->email], 'Agent invitation');
    }

    public function accept(Request $r, OnboardingAgentService $service)
    {
        $r->validate(['password' => ['required', 'string', 'confirmed', 'min:8']]);
        DB::transaction(function () use ($r, $service) {
            [$i,$u] = $this->invitation($r);
            $u = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            $i = DB::table('agent_invitations')->where('id', $i->id)->lockForUpdate()->first();
            abort_unless($u->is_active && ! $i->accepted_at && now()->lt($i->expires_at), 422, 'Invitation no longer available.');
            $u->update(['password' => $r->password, 'email_verified_at' => now(), 'must_change_password' => false]);
            $u->tokens()->delete();
            DB::table('agent_invitations')->where('id', $i->id)->update(['accepted_at' => now()]);
            $service->audit('agent.accepted', $u->id);
        });

        return $this->sendResponse([], 'Invitation accepted. Sign in through the Business portal.');
    }
}
