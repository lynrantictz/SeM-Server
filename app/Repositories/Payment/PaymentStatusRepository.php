<?php

namespace App\Repositories\Payment;

use App\Models\Payment\PaymentStatus;
use App\Repositories\BaseRepository;

class PaymentStatusRepository extends BaseRepository
{
    const MODEL = PaymentStatus::class;
}
