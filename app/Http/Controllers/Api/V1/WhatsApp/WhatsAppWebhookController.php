<?php

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\Order\OrderCheckoutVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        abort_unless(
            $mode === 'subscribe'
            && is_string($token)
            && hash_equals((string) config('whatsapp.verify_token'), $token)
            && is_string($challenge),
            HTTP_FORBIDDEN,
            'Webhook verification failed.'
        );

        return response($challenge, HTTP_OK)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request)
    {
        if (config('whatsapp.verify_webhook_signature')) {
            $signature = (string) $request->header('X-Hub-Signature-256');
            $secret = (string) config('whatsapp.app_secret');
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

            abort_unless($secret !== '' && hash_equals($expected, $signature), HTTP_FORBIDDEN, 'Invalid webhook signature.');
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                foreach (data_get($change, 'value.statuses', []) as $status) {
                    $this->recordStatus($status);
                }
            }
        }

        return response()->json(['success' => true]);
    }

    private function recordStatus(array $status): void
    {
        $messageId = data_get($status, 'id');
        $deliveryStatus = strtolower((string) data_get($status, 'status'));
        if (! is_string($messageId) || $messageId === '' || ! in_array($deliveryStatus, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        DB::transaction(function () use ($messageId, $deliveryStatus, $status): void {
            $checkout = OrderCheckoutVerification::query()
                ->where('whatsapp_message_id', $messageId)
                ->lockForUpdate()
                ->first();
            if (! $checkout) {
                return;
            }

            $checkout->forceFill([
                'whatsapp_status' => $deliveryStatus,
                'whatsapp_failure_reason' => $deliveryStatus === 'failed'
                    ? str((string) data_get($status, 'errors.0.title', 'WhatsApp could not deliver this message.'))->squish()->limit(500, '…')->toString()
                    : null,
            ])->save();
        });
    }
}
