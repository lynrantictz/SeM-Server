<?php

namespace App\Repositories\Payment;

use App\Models\Payment\PaymentGateway;
use App\Repositories\BaseRepository;

class PaymentGatewayRepository extends BaseRepository
{
    const MODEL = PaymentGateway::class;
}
