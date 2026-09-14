<?php

namespace App\Models\Order;

use App\Models\BaseModel;

class OrderCustomerSession extends BaseModel
{
    protected $fillable = ['business_id', 'customer_id', 'token_hash', 'expires_at'];

    protected $hidden = ['customer_id', 'token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
