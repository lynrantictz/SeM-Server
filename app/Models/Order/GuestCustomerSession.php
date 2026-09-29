<?php

namespace App\Models\Order;

use App\Models\BaseModel;

class GuestCustomerSession extends BaseModel
{
    protected $fillable = ['customer_id', 'token_hash', 'expires_at'];

    protected $hidden = ['customer_id', 'token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
