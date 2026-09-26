<?php

namespace App\Jobs\WhatsApp;

use App\Models\Order\OrderCheckoutVerification;
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

class SendOrderCheckoutVerificationWhatsApp implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A timed-out provider request may have delivered the OTP, so do not retry automatically. */
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $checkoutUuid,
        public readonly string $code,
        public readonly int $sendVersion,
    ) {
    }

    public function handle(WhatsAppMessagingService $whatsApp): void
    {
        $checkout = DB::transaction(function () {
            $record = OrderCheckoutVerification::query()
                ->where('uuid', $this->checkoutUuid)
                ->lockForUpdate()
                ->first();

            if (! $record || $record->order_id || $record->verified_at || $record->whatsapp_send_version !== $this->sendVersion) {
                return null;
            }

            $record->forceFill([
                'whatsapp_status' => 'sending',
                'whatsapp_last_attempt_at' => now(),
                'whatsapp_failure_reason' => null,
            ])->save();

            return $record;
        });

        if (! $checkout) {
            return;
        }

        try {
            $result = $whatsApp->sendOrderVerificationCode($checkout->phone, $this->code, $checkout->uuid);

            DB::transaction(function () use ($result) {
                $record = OrderCheckoutVerification::query()
                    ->where('uuid', $this->checkoutUuid)
                    ->lockForUpdate()
                    ->first();

                if (! $record || $record->whatsapp_send_version !== $this->sendVersion) {
                    return;
                }

                $record->forceFill([
                    'whatsapp_status' => 'sent',
                    'whatsapp_message_id' => $result->messageId,
                    'whatsapp_sent_at' => now(),
                    'whatsapp_failure_reason' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($exception) {
                $record = OrderCheckoutVerification::query()
                    ->where('uuid', $this->checkoutUuid)
                    ->lockForUpdate()
                    ->first();

                if (! $record || $record->whatsapp_send_version !== $this->sendVersion) {
                    return;
                }

                $record->forceFill([
                    'whatsapp_status' => 'failed',
                    'whatsapp_failure_reason' => str($exception->getMessage())->squish()->limit(500, '…')->toString(),
                ])->save();
            });

            Log::error('WhatsApp checkout verification delivery failed.', [
                'checkout_uuid' => $this->checkoutUuid,
                'send_version' => $this->sendVersion,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
