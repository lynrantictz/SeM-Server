<?php

namespace App\Models\Order;

use App\Models\Auth\User;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStaffNote extends BaseModel
{
    protected $fillable = ['order_id', 'user_id', 'body'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
}
