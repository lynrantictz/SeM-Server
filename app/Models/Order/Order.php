<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Order\Trait\Attribute\OrderAttribute;
use App\Models\Order\Trait\Relationship\OrderRelationship;

class Order extends BaseModel
{
    use OrderAttribute, OrderRelationship;

    protected $casts = [
        'order_status_id' => 'integer',
        'payment_status_id' => 'integer',
        'assigned_to_user_id' => 'integer',
    ];

    protected $appends = ['created_at_formatted'];
}
