<?php

namespace App\Services\PaymentGateway\Providers;

use App\Services\PaymentGateway\Contracts\PaymentGatewayInterface;
use App\Services\PaymentGateway\DTOs\PaymentRequestData;
use Illuminate\Support\Facades\Http;
use Exception;

class AzamPayService implements PaymentGatewayInterface
{
    private string $baseUrl;
    private string $appKey;
    private string $appSecret;

    public function __construct()
    {
        $this->baseUrl = config('payments.azampay.base_url');
        $this->appKey = config('payments.azampay.app_key');
        $this->appSecret = config('payments.azampay.app_secret');
    }

    public function initiatePayment(array|PaymentRequestData $data): array
    {
        if ($data instanceof PaymentRequestData) {
            $data = [
                'reference' => $data->reference,
                'amount' => $data->amount,
                'phone' => $data->phone,
                'currency' => $data->currency,
            ];
        }

        $response = Http::withHeaders([
            'AppKey' => $this->appKey,
            'AppSecret' => $this->appSecret
        ])->post($this->baseUrl . '/azampay/payment/initiate', $data);

        if (!$response->successful()) {
            throw new Exception('AzamPay error: ' . $response->body());
        }

        return $response->json();
    }

    public function verifyPayment(string $transactionId): array
    {
        $response = Http::get($this->baseUrl . "/azampay/payment/verify/{$transactionId}");

        return $response->json();
    }

    public function handleCallback(array $payload): array
    {
        return [
            'transaction_id' => $payload['transaction_id'],
            'status' => $payload['status'],
            'amount' => $payload['amount'],
        ];
    }
}
