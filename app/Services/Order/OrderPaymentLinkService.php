<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderPaymentLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrderPaymentLinkService
{
    public function issue(Order $order, ?int $userId = null): array
    {
        return DB::transaction(function () use ($order, $userId): array {
            OrderPaymentLink::query()
                ->where('order_id', $order->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['revoked_at' => now()]);

            $secret = Str::random(64);
            $link = OrderPaymentLink::query()->create([
                'order_id' => $order->id,
                'token_hash' => Hash::make($secret),
                'expires_at' => now()->addHours((int) config('payments.payment_link_lifetime_hours', 24)),
                'created_by_user_id' => $userId,
                'whatsapp_status' => 'queued',
            ]);

            return ['link' => $link, 'token' => $link->uuid . '.' . $secret];
        });
    }

    public function resolve(string $token): ?OrderPaymentLink
    {
        [$uuid, $secret] = array_pad(explode('.', $token, 2), 2, null);
        if (! $uuid || ! $secret) return null;

        $link = OrderPaymentLink::query()
            ->where('uuid', $uuid)->whereNull('revoked_at')->where('expires_at', '>', now())->first();

        return $link && Hash::check($secret, $link->token_hash) ? $link : null;
    }

    public function url(string $token): string
    {
        return rtrim((string) config('paperstick.client_url'), '/') . '/pay/' . rawurlencode($token);
    }
}
