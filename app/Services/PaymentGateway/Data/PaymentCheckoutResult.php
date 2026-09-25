<?php

namespace App\Services\PaymentGateway\Data;

use App\Models\Payment\Payment;

readonly class PaymentCheckoutResult
{
    public function __construct(
        public Payment $payment,
        public bool $promptSent,
        public bool $awaitingGatewayConfirmation = false,
        public bool $queued = false,
    ) {
    }
}
