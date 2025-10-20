<?php

namespace App\Models\Section;

use App\Models\BaseModel;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Code extends BaseModel
{
    /**
     * Get the parent codable model (section or other models).
     */
    public function codable(): MorphTo
    {
        return $this->morphTo();
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
