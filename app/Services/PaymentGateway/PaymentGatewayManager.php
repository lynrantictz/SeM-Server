<?php

namespace App\Services\PaymentGateway;

use App\Services\PaymentGateway\Contracts\PaymentGatewayInterface;
use App\Services\PaymentGateway\Providers\AzamPayService;
use Exception;

class PaymentGatewayManager
{
    public function gateway(string $name): PaymentGatewayInterface
    {
        return match ($name) {
            'azampay' => new AzamPayService(),
            default   => throw new Exception("Payment gateway {$name} not supported."),
        };
    }
}

// in controller

// public function pay()
// {
//     $data = new PaymentRequestData(
//         reference: 'ORDER_12345',
//         amount: 20000,
//         phone: '255678000000'
//     );

//     $gateway = app(PaymentGatewayManager::class)->gateway('azampay');

//     return $gateway->initiatePayment($data);
// }
