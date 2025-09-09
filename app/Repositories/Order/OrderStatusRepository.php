<?php

namespace App\Repositories\Order;

use App\Models\Order\OrderStatus;
use App\Repositories\BaseRepository;

class OrderStatusRepository extends BaseRepository
{
    const MODEL = OrderStatus::class;
}
