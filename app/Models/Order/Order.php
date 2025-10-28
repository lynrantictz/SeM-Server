<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Order\Trait\Attribute\OrderAttribute;
use App\Models\Order\Trait\Relationship\OrderRelationship;

class Order extends BaseModel
{
    use OrderAttribute, OrderRelationship;

    protected $appends = ['created_at_formatted'];
}
