<?php

namespace App\Jobs\WhatsApp;

use App\Models\Order\OrderPaymentLink;
use App\Services\WhatsApp\WhatsAppMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOrderPaymentRequestWhatsApp implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public readonly string $paymentLinkUuid, public readonly string $token) {}

    public function handle(WhatsAppMessagingService $whatsApp): void
    {
        $link = OrderPaymentLink::query()->with('order.customer', 'order.business')->where('uuid', $this->paymentLinkUuid)->first();
        $recipientPhone = $link?->recipient_phone_e164 ?: $link?->order?->customer?->phone_e164;
        if (! $link || $link->revoked_at || $link->expires_at->isPast() || ! $recipientPhone) return;

        try {
            $result = $whatsApp->sendOrderPaymentRequest(
                $recipientPhone,
                $link->order->business->name,
                $link->order->number,
                $link->order->business->currency ?? 'TZS',
                number_format((float) $link->order->total_amount),
                $this->token,
            );
            DB::transaction(fn () => $link->forceFill(['whatsapp_status' => 'sent', 'whatsapp_message_id' => $result->messageId, 'whatsapp_sent_at' => now(), 'whatsapp_failure_reason' => null])->save());
        } catch (Throwable $exception) {
            DB::transaction(fn () => $link->forceFill(['whatsapp_status' => 'failed', 'whatsapp_failure_reason' => str($exception->getMessage())->squish()->limit(500, '…')->toString()])->save());
            Log::error('WhatsApp payment request delivery failed.', ['payment_link_uuid' => $link->uuid, 'exception' => $exception->getMessage()]);
        }
    }
}
