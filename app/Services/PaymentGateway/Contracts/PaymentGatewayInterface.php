<?php

namespace App\Services\PaymentGateway\Contracts;

interface PaymentGatewayInterface
{
    public function initiatePayment(array $data): array;

    public function verifyPayment(string $transactionId): array;

    public function handleCallback(array $payload): array;
}
