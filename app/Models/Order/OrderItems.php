<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Order\Trait\Attribute\OrderItemAttribute;
use App\Models\Order\Trait\Relationship\OrderItemRelationship;

class OrderItems extends BaseModel
{
    use OrderItemAttribute, OrderItemRelationship;
}
