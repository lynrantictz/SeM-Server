<?php

namespace App\Repositories\Order;

use App\Models\Order\OrderItem;
use App\Repositories\BaseRepository;

class OrderItemRepository extends BaseRepository
{
    const MODEL = OrderItem::class;
}
