<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderPaymentLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OrderPaymentLinkService
{
    public function issue(Order $order, ?int $userId = null, ?string $recipientPhoneE164 = null, ?string $deliveryInitiator = null, bool $revokeExisting = true): array
    {
        return DB::transaction(function () use ($order, $userId, $recipientPhoneE164, $deliveryInitiator, $revokeExisting): array {
            if ($revokeExisting) {
                OrderPaymentLink::query()
                    ->where('order_id', $order->id)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->update(['revoked_at' => now()]);
            }

            $secret = Str::random(64);
            $link = OrderPaymentLink::query()->create([
                'order_id' => $order->id,
                'token_hash' => Hash::make($secret),
                'expires_at' => now()->addHours((int) config('payments.payment_link_lifetime_hours', 24)),
                'created_by_user_id' => $userId,
                'recipient_phone_e164' => $recipientPhoneE164,
                'delivery_initiator' => $deliveryInitiator,
                'whatsapp_status' => 'queued',
            ]);

            return ['link' => $link, 'token' => $link->uuid . '.' . $secret];
        });
    }

    public function resolve(string $token): ?OrderPaymentLink
    {
        return $this->resolveWithStatus($token)['link'];
    }

    /** @return array{link: ?OrderPaymentLink, status: string} */
    public function resolveWithStatus(string $token): array
    {
        [$uuid, $secret] = array_pad(explode('.', $token, 2), 2, null);
        if (! $uuid || ! $secret) return ['link' => null, 'status' => 'invalid'];

        $link = OrderPaymentLink::query()->where('uuid', $uuid)->first();
        if (! $link || ! Hash::check($secret, $link->token_hash)) {
            return ['link' => null, 'status' => 'invalid'];
        }
        if ($link->revoked_at) return ['link' => null, 'status' => 'revoked'];
        if ($link->expires_at->isPast()) return ['link' => null, 'status' => 'expired'];

        return ['link' => $link, 'status' => 'active'];
    }

    public function customerShareCount(Order $order): int
    {
        return OrderPaymentLink::query()
            ->where('order_id', $order->id)
            ->where('delivery_initiator', 'customer')
            ->count();
    }

    public function customerShareActivity(Order $order): array
    {
        return OrderPaymentLink::query()
            ->where('order_id', $order->id)
            ->where('delivery_initiator', 'customer')
            ->latest('id')
            ->get()
            ->map(fn (OrderPaymentLink $link) => [
                'recipient' => $this->maskPhone($link->recipient_phone_e164),
                'status' => $link->whatsapp_status ?? 'queued',
                'sent_at' => $link->whatsapp_sent_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function maskPhone(?string $phone): ?string
    {
        if (! $phone || strlen($phone) < 7) return null;
        return '+' . substr($phone, 0, 3) . ' ' . substr($phone, 3, 1) . '••• ••• ' . substr($phone, -3);
    }

    public function url(string $token): string
    {
        return rtrim((string) config('paperstick.client_url'), '/') . '/pay/' . rawurlencode($token);
    }
}
