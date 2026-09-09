<?php

namespace App\Models\Order\Trait\Relationship;

use App\Models\Menu\Item;
use App\Models\Order\Order;
use App\Models\Order\OrderItemOption;

trait OrderItemRelationship
{

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function options()
    {
        return $this->hasMany(OrderItemOption::class);
    }

}
