<?php

namespace App\Services\PaymentGateway\DTOs;

class PaymentCallbackData
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $status,
        public readonly float $amount
    ) {}
}
