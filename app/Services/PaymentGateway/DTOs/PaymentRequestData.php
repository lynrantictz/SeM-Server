<?php

namespace App\Services\PaymentGateway\DTOs;

class PaymentRequestData
{
    public function __construct(
        public readonly string $reference,
        public readonly float $amount,
        public readonly string $phone,
        public readonly string $currency = 'TZS'
    ) {}
}
