<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppMessagingService
{
    public function sendOrderPaymentRequest(string $phone, string $businessName, string $orderNumber, string $amount, string $paymentToken): WhatsAppMessageResult
    {
        if (config('whatsapp.driver') === 'log') {
            Log::info('Order payment request (local testing only)', compact('phone', 'businessName', 'orderNumber', 'amount', 'paymentToken'));
            return new WhatsAppMessageResult("local-payment-{$orderNumber}");
        }

        $response = $this->metaClient()->post(sprintf('/%s/%s/messages', $this->graphVersion(), config('whatsapp.phone_number_id')), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => config('whatsapp.order_payment_template'),
                'language' => ['code' => config('whatsapp.template_language')],
                'components' => [
                    ['type' => 'body', 'parameters' => [
                        ['type' => 'text', 'text' => $businessName],
                        ['type' => 'text', 'text' => $orderNumber],
                        ['type' => 'text', 'text' => $amount],
                    ]],
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [
                        ['type' => 'text', 'text' => $paymentToken],
                    ]],
                ],
            ],
        ]);

        return $this->result($response);
    }

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

        $response = $this->metaClient()->post(sprintf('/%s/%s/messages', $this->graphVersion(), config('whatsapp.phone_number_id')), [
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

        return $this->result($response);
    }

    private function result($response): WhatsAppMessageResult
    {
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

    private function metaClient(): PendingRequest
    {
        $accessToken = (string) config('whatsapp.access_token');
        $phoneNumberId = (string) config('whatsapp.phone_number_id');
        if ($accessToken === '' || $phoneNumberId === '') throw new RuntimeException('Meta WhatsApp credentials are not configured.');
        return $this->client()->withToken($accessToken);
    }

    private function graphVersion(): string
    {
        $version = trim((string) config('whatsapp.graph_version'));
        if ($version === '') throw new RuntimeException('Meta WhatsApp credentials are not configured.');
        return 'v' . ltrim($version, 'vV');
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
