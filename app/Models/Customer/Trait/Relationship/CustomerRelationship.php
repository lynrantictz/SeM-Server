<?php

namespace App\Models\Customer\Trait\Relationship;

use App\Models\Order\Order;

trait CustomerRelationship
{
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
