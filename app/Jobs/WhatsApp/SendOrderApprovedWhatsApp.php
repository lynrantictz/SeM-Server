<?php

namespace App\Jobs\WhatsApp;

use App\Models\Order\Order;
use App\Services\WhatsApp\WhatsAppMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOrderApprovedWhatsApp implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public readonly string $orderUuid) {}

    public function handle(WhatsAppMessagingService $whatsApp): void
    {
        $order = Order::query()
            ->with(['customer', 'business.paymentSetting'])
            ->where('uuid', $this->orderUuid)
            ->first();

        if (! $order) {
            Log::warning('WhatsApp order approval job skipped: order not found.', [
                'order_uuid' => $this->orderUuid,
            ]);
            return;
        }

        $phone = $order?->customer?->phone_e164;
        if (! $order->approved_at || ! $phone || ! $order->business) {
            Log::warning('WhatsApp order approval job skipped because required order details are missing.', [
                'order_uuid' => $order->uuid,
                'order_number' => $order->number,
                'approved' => (bool) $order->approved_at,
                'has_customer_phone' => (bool) $phone,
                'has_business' => (bool) $order->business,
            ]);
            return;
        }

        $currency = $order->business->paymentSetting?->currency ?? 'TZS';
        $total = $currency . ' ' . number_format((float) $order->total_amount, 0, '.', ',');

        try {
            $result = $whatsApp->sendOrderApproved(
                $phone,
                $order->number,
                $order->business->name,
                $total,
            );

            Log::info('WhatsApp order approval notification processed.', [
                'order_uuid' => $order->uuid,
                'order_number' => $order->number,
                'message_id' => $result->messageId,
            ]);
        } catch (Throwable $exception) {
            Log::error('WhatsApp order approval notification failed.', [
                'order_uuid' => $order->uuid,
                'order_number' => $order->number,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
