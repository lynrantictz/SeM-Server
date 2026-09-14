<?php

namespace App\Models\Order;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderStatusHistory extends Model
{
    protected $fillable = [
        'order_id', 'from_status_id', 'to_status_id', 'changed_by_user_id', 'note',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $history) {
            $history->uuid ??= (string) Str::uuid();
        });
    }

    public function fromStatus()
    {
        return $this->belongsTo(OrderStatus::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(OrderStatus::class, 'to_status_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
