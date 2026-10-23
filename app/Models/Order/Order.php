<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Order\Trait\Attribute\OrderAttribute;
use App\Models\Order\Trait\Relationship\OrderRelationship;

class Order extends BaseModel
{
    use OrderAttribute, OrderRelationship;

    protected $hidden = [
        'receipt_token',
    ];

    protected $casts = [
        'order_status_id' => 'integer',
        'payment_status_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'scheduled_for' => 'datetime',
        'preparation_starts_at' => 'datetime',
    ];

    protected $appends = ['created_at_formatted'];
}
