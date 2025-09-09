<?php

namespace App\Repositories\Payment;

use App\Models\Payment\PaymentMethod;
use App\Repositories\BaseRepository;

class PaymentMethodRepository extends BaseRepository
{
    const MODEL = PaymentMethod::class;
}
