<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use App\Models\Business\Business;
use App\Models\Customer\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFeedback extends BaseModel
{
    protected $fillable = [
        'order_id',
        'business_id',
        'customer_id',
        'type',
        'rating',
        'comment',
        'submitted_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
