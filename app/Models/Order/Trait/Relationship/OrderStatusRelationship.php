<?php

namespace App\Models\Order\Trait\Relationship;

use App\Models\Order\Order;

trait OrderStatusRelationship
{
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
