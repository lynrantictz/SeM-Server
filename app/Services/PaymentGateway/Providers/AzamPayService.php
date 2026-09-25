<?php

namespace App\Services\PaymentGateway\Providers;

use App\Services\PaymentGateway\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AzamPayService implements PaymentGatewayInterface
{
    private readonly string $baseUrl;
    private readonly string $tokenBaseUrl;
    private readonly string $tokenPath;
    private readonly string $mnoCheckoutPath;
    private readonly string $publicKeyPath;
    private readonly string $clientId;
    private readonly string $clientSecret;
    private readonly string $appName;
    private readonly int $timeoutSeconds;
    private readonly int $checkoutTimeoutSeconds;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('payments.azampay.base_url'), '/');
        $this->tokenBaseUrl = rtrim((string) config('payments.azampay.token_base_url'), '/');
        $this->tokenPath = (string) config('payments.azampay.token_path');
        $this->mnoCheckoutPath = (string) config('payments.azampay.mno_checkout_path');
        $this->publicKeyPath = (string) config('payments.azampay.public_key_path');
        $this->clientId = (string) config('payments.azampay.client_id');
        $this->clientSecret = (string) config('payments.azampay.client_secret');
        $this->appName = (string) config('payments.azampay.app_name');
        $this->timeoutSeconds = (int) config('payments.azampay.timeout_seconds', 20);
        $this->checkoutTimeoutSeconds = (int) config('payments.azampay.checkout_timeout_seconds', 60);
    }

    public function mnoCheckout(array $data): array
    {
        $response = $this->checkoutClient()->withToken($this->accessToken())->post($this->mnoCheckoutPath, $data);

        if (! $response->successful()) {
            $message = data_get($response->json(), 'message')
                ?? data_get($response->json(), 'title')
                ?? 'AzamPay MNO checkout request failed.';

            throw new RuntimeException("{$message} (HTTP {$response->status()}).");
        }

        return $response->json() ?: [];
    }

    public function verifyPayment(string $transactionId): array
    {
        return ['transaction_id' => $transactionId];
    }

    public function paymentPartners(): array
    {
        return Cache::remember('payments.azampay.payment-partners', now()->addHour(), function (): array {
            $response = $this->client()->withToken($this->accessToken())->get('/api/v1/Partner/GetPaymentPartners');
            if (! $response->successful()) {
                throw new RuntimeException('Unable to retrieve AzamPay mobile-money providers.');
            }

            $partners = data_get($response->json(), 'data', $response->json());
            return is_array($partners) ? $partners : [];
        });
    }

    public function handleCallback(array $payload): array
    {
        return [
            'transaction_id' => $payload['transid'] ?? null,
            'external_reference' => $payload['externalreference'] ?? null,
            'utility_reference' => $payload['utilityref'] ?? null,
            'status' => strtolower((string) ($payload['transactionstatus'] ?? '')),
            'amount' => $payload['amount'] ?? null,
            'operator' => $payload['operator'] ?? null,
        ];
    }

    public function verifyCallbackSignature(array $payload): bool
    {
        $signature = $payload['signature'] ?? null;
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $data = implode('', [
            (string) ($payload['utilityref'] ?? ''),
            (string) ($payload['externalreference'] ?? ''),
            (string) ($payload['transactionstatus'] ?? ''),
            (string) ($payload['operator'] ?? ''),
        ]);

        return openssl_verify($data, base64_decode($signature, true) ?: '', $this->publicKey(), OPENSSL_ALGO_SHA256) === 1;
    }

    private function accessToken(): string
    {
        return Cache::remember('payments.azampay.access-token', now()->addMinutes(25), function (): string {
            if ($this->clientId === '' || $this->clientSecret === '') {
                throw new RuntimeException('AzamPay sandbox credentials are not configured.');
            }

            $response = $this->tokenClient()->post($this->tokenPath, [
                'appName' => $this->appName,
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
            ]);

            if (! $response->successful()) {
                $message = data_get($response->json(), 'message')
                    ?? data_get($response->json(), 'title')
                    ?? data_get($response->json(), 'error.message')
                    ?? 'No reason was supplied by AzamPay.';
                $message = str($message)->squish()->limit(500, '…')->toString();

                throw new RuntimeException("AzamPay token request failed (HTTP {$response->status()}): {$message}");
            }

            $token = data_get($response->json(), 'data.accessToken') ?? data_get($response->json(), 'accessToken');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('AzamPay did not return an access token.');
            }

            return $token;
        });
    }

    private function publicKey(): string
    {
        return Cache::remember('payments.azampay.public-key', now()->addDay(), function (): string {
            $response = $this->client()->withToken($this->accessToken())->get($this->publicKeyPath, ['format' => 'Pem']);
            if (! $response->successful()) {
                throw new RuntimeException('Unable to retrieve the AzamPay callback public key.');
            }

            $key = data_get($response->json(), 'data.publicKey') ?? data_get($response->json(), 'publicKey') ?? $response->body();
            if (! is_string($key) || ! str_contains($key, 'BEGIN PUBLIC KEY')) {
                throw new RuntimeException('AzamPay returned an invalid callback public key.');
            }

            return $key;
        });
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->acceptJson()->asJson()
            ->timeout($this->timeoutSeconds)->connectTimeout(5);
    }

    private function tokenClient(): PendingRequest
    {
        return Http::baseUrl($this->tokenBaseUrl)->acceptJson()->asJson()
            ->timeout($this->timeoutSeconds)->connectTimeout(5);
    }

    private function checkoutClient(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->acceptJson()->asJson()
            ->timeout($this->checkoutTimeoutSeconds)->connectTimeout(5);
    }
}
