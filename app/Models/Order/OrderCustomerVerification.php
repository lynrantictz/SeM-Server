<?php

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;

class OrderCustomerVerification extends Model
{
    protected $fillable = [
        'phone',
        'verification_code'
    ];
}
