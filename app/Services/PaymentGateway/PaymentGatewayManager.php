<?php

namespace App\Services\PaymentGateway;

use App\Services\PaymentGateway\Contracts\PaymentGatewayInterface;
use App\Services\PaymentGateway\Providers\AzamPayService;
use InvalidArgumentException;

class PaymentGatewayManager
{
    public function gateway(string $name): PaymentGatewayInterface
    {
        abort_unless(config('payments.azampay.enabled', false), 422, 'Online gateway payments are currently unavailable.');
        return match ($name) {
            'azampay' => app(AzamPayService::class),
            default => throw new InvalidArgumentException("Payment gateway {$name} is not supported."),
        };
    }
}
