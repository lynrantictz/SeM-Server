<?php

namespace App\Models\Customer\Trait\Relationship;

use App\Models\Order;

trait CustomerRelationship
{
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
