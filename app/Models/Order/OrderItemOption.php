<?php

namespace App\Models\Order;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemOption extends BaseModel
{
    protected $guarded = ['uuid'];

    protected $casts = ['price_adjustment' => 'float'];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
