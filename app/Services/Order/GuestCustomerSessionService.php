<?php

namespace App\Services\Order;

use App\Models\Customer\Customer;
use App\Models\Order\GuestCustomerSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GuestCustomerSessionService
{
    public function issue(Customer $customer): array
    {
        return DB::transaction(function () use ($customer): array {
            $secret = Str::random(64);
            $session = GuestCustomerSession::query()->create([
                'customer_id' => $customer->id,
                'token_hash' => Hash::make($secret),
                'expires_at' => now()->addDays((int) config('guest-session.lifetime_days', 7)),
            ]);

            return ['session' => $session, 'access_token' => $session->uuid . '.' . $secret];
        });
    }

    public function resolveFromRequest(Request $request): ?GuestCustomerSession
    {
        return $this->resolve($request->cookie(config('guest-session.cookie')));
    }

    public function resolve(?string $accessToken): ?GuestCustomerSession
    {
        [$uuid, $secret] = array_pad(explode('.', (string) $accessToken, 2), 2, null);
        if (! $uuid || ! $secret) return null;

        $session = GuestCustomerSession::query()->where('uuid', $uuid)->where('expires_at', '>', now())->first();
        return $session && Hash::check($secret, $session->token_hash) ? $session : null;
    }

    public function forgetFromRequest(Request $request): bool
    {
        $session = $this->resolveFromRequest($request);
        if (! $session) return false;

        DB::transaction(fn () => $session->delete());
        return true;
    }
}
