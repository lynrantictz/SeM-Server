<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderCustomerSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GuestOrderSessionService
{
    public function issueForOrder(Order $order, int $minutes = 15): array
    {
        return DB::transaction(function () use ($order, $minutes): array {
            $secret = Str::random(48);
            $session = OrderCustomerSession::query()->create([
                'business_id' => $order->business_id,
                'customer_id' => $order->customer_id,
                'token_hash' => Hash::make($secret),
                'expires_at' => now()->addMinutes($minutes),
            ]);

            return [
                'access_token' => $session->uuid . '.' . $secret,
                'expires_at' => $session->expires_at,
            ];
        });
    }

    public function resolveForOrder(Order $order, ?string $accessToken): ?OrderCustomerSession
    {
        $session = $this->resolve($accessToken);

        return $session
            && (int) $session->business_id === (int) $order->business_id
            && (int) $session->customer_id === (int) $order->customer_id
            ? $session
            : null;
    }

    public function resolveForBusiness(int $businessId, ?string $accessToken): ?OrderCustomerSession
    {
        $session = $this->resolve($accessToken);

        return $session && (int) $session->business_id === $businessId
            ? $session
            : null;
    }

    private function resolve(?string $accessToken): ?OrderCustomerSession
    {
        [$sessionUuid, $secret] = array_pad(explode('.', (string) $accessToken, 2), 2, null);
        if (! $sessionUuid || ! $secret) {
            return null;
        }

        $session = OrderCustomerSession::query()
            ->where('uuid', $sessionUuid)
            ->where('expires_at', '>', now())
            ->first();

        return $session && Hash::check($secret, $session->token_hash)
            ? $session
            : null;
    }
}
