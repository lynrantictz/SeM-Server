<?php

namespace App\Models\Order;

use App\Models\Auth\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderAssignmentHistory extends Model
{
    protected $fillable = [
        'uuid', 'order_id', 'previous_waiter_id', 'waiter_id', 'changed_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $history) {
            $history->uuid ??= (string) Str::uuid();
        });
    }

    public function previousWaiter()
    {
        return $this->belongsTo(User::class, 'previous_waiter_id');
    }

    public function waiter()
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
