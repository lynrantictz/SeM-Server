<?php

namespace App\Models\Order;

use App\Models\Order\Trait\Attribute\OrderStatusAttribute;
use App\Models\Order\Trait\Relationship\OrderStatusRelationship;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    use OrderStatusAttribute, OrderStatusRelationship;
}
