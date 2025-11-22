<?php

namespace App\Services\PaymentGateway\Providers;

use App\Repositories\PaymentTransactionRepository;
use App\Services\PaymentGateway\Contracts\PaymentGatewayInterface;
use App\Services\PaymentGateway\DTOs\MnoCheckoutData;
use Illuminate\Support\Facades\Http;
use Exception;

class AzamPayService implements PaymentGatewayInterface
{
    private string $baseUrl;
    private string $tokenUrl;
    private string $appKey;
    private string $appSecret;

    public function __construct()
    {
        $this->baseUrl = config('payments.azampay.base_url');
        $this->tokenUrl = config('payments.azampay.token_url');
        $this->appKey = config('payments.azampay.app_key');
        $this->appSecret = config('payments.azampay.app_secret');
    }

    private function getToken(): string
    {
        $response = Http::post($this->tokenUrl, [
            'appKey' => $this->appKey,
            'appSecret' => $this->appSecret
        ]);

        if (!$response->successful()) {
            throw new Exception("AzamPay Token Error: " . $response->body());
        }

        return $response->json()['data']['accessToken'];
    }

    public function mnoCheckout(array|MnoCheckoutData $data): array
    {
        $transactionRepo = new PaymentTransactionRepository();

        // Store initial transaction
        $transaction = $transactionRepo->create([
            'external_id' => $data->externalId,
            'account_number' => $data->accountNumber,
            'provider' => $data->provider,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'status' => 'PENDING',
            'request_payload' => $data,
        ]);

        // Get accessToken
        $token = $this->getToken();

        // Send API request
        $response = Http::withToken($token)
            ->post($this->baseUrl . '/azampay/mno/checkout', $data);

        $json = $response->json();

        // Update transaction
        $transactionRepo->updateAzamPayId($transaction->id, $json['transactionId'] ?? null);
        $transactionRepo->updateStatus($transaction->id, $json['success'] ? 'PROCESSING' : 'FAILED');

        return $json;
    }

    public function verifyPayment(string $transactionId): array
    {
        // Optional: implement AzamPay verification
        return [];
    }

    public function handleCallback(array $payload): array
    {
        return [
            'transactionId' => $payload['transactionId'] ?? null,
            'status'        => $payload['status'] ?? null,
            'amount'        => $payload['amount'] ?? null,
        ];
    }
}
