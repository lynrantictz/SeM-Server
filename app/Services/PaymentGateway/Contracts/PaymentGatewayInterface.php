<?php

namespace App\Services\PaymentGateway\Contracts;

interface PaymentGatewayInterface
{
    public function mnoCheckout(array $data): array;

    public function verifyPayment(string $transactionId): array;

    public function handleCallback(array $payload): array;
}
