<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppMessagingService
{
    public function sendOrderVerificationCode(string $phone, string $code, string $checkoutUuid): WhatsAppMessageResult
    {
        if (config('whatsapp.driver') === 'log') {
            Log::info('Order checkout verification code (local testing only)', [
                'checkout_uuid' => $checkoutUuid,
                'otp' => $code,
            ]);

            return new WhatsAppMessageResult("local-{$checkoutUuid}");
        }

        if (config('whatsapp.driver') !== 'meta') {
            throw new RuntimeException('The configured WhatsApp delivery driver is not supported.');
        }

        $accessToken = (string) config('whatsapp.access_token');
        $phoneNumberId = (string) config('whatsapp.phone_number_id');
        $graphVersion = (string) config('whatsapp.graph_version');
        if ($accessToken === '' || $phoneNumberId === '' || $graphVersion === '') {
            throw new RuntimeException('Meta WhatsApp credentials are not configured.');
        }

        $response = $this->client()
            ->withToken($accessToken)
            ->post(sprintf('/%s/%s/messages', $graphVersion, $phoneNumberId), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
                'type' => 'template',
                'template' => [
                    'name' => config('whatsapp.order_verification_template'),
                    'language' => ['code' => config('whatsapp.template_language')],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [['type' => 'text', 'text' => $code]],
                        ],
                        [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => '0',
                            'parameters' => [['type' => 'text', 'text' => $code]],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            $reason = data_get($response->json(), 'error.message')
                ?? data_get($response->json(), 'message')
                ?? 'Meta WhatsApp rejected the verification message.';

            throw new RuntimeException("{$reason} (HTTP {$response->status()}).");
        }

        $messageId = data_get($response->json(), 'messages.0.id');
        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('Meta WhatsApp did not return a message identifier.');
        }

        return new WhatsAppMessageResult($messageId);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com')
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('whatsapp.timeout_seconds'))
            ->connectTimeout(5);
    }
}
