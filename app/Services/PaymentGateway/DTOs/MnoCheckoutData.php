<?php

namespace App\Services\PaymentGateway\DTOs;

class MnoCheckoutData
{
    public function __construct(
        public readonly string $accountNumber,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $externalId,
        public readonly string $provider,
        public readonly ?array $additionalProperties = null,
    ) {}
}
