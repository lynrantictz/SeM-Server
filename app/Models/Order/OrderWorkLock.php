<?php

namespace App\Models\Order;

use App\Models\Auth\User;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderWorkLock extends BaseModel
{
    protected $fillable = ['order_id', 'user_id', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
