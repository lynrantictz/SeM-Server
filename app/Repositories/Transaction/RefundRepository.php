<?php

namespace App\Repositories\Transaction;

use App\Models\Transaction\Refund;
use App\Repositories\BaseRepository;

class RefundRepository extends BaseRepository
{
    const MODEL = Refund::class;
}
