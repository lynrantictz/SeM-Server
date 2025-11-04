<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Order\Trait\Attribute\OrderItemAttribute;
use App\Models\Order\Trait\Relationship\OrderItemRelationship;

class OrderItem extends BaseModel
{
    use OrderItemAttribute, OrderItemRelationship;

    protected $casts = [
        'unit_price' => 'float',
        'total_amount' => 'float',
        'quantity' => 'integer',
    ];
}
