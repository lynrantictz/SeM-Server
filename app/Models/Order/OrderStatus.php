<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Order\Trait\Attribute\OrderStatusAttribute;
use App\Models\Order\Trait\Relationship\OrderStatusRelationship;

class OrderStatus extends BaseModel
{
    use OrderStatusAttribute, OrderStatusRelationship;
}
