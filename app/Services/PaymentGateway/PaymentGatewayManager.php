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

// use App\Services\PaymentGateway\DTOs\MnoCheckoutData;
// use App\Services\PaymentGateway\PaymentGatewayManager;

// public function checkout()
// {
//     $dto = new MnoCheckoutData(
//         accountNumber: '255713000000',
//         amount: 2000,
//         currency: 'TZS',
//         externalId: 'INV-001',
//         provider: 'Airtel',
//         additionalProperties: [
//             'order_id' => 123,
//             'customer_name' => 'HAMIS JUMA'
//         ]
//     );

//     $gateway = app(PaymentGatewayManager::class)->gateway('azampay');

//     return $gateway->mnoCheckout($dto);
// }
