<?php

namespace App\Models\Discovery;

use App\Models\Business\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryInteraction extends Model
{
    protected $fillable = [
        'business_id',
        'event_type',
        'ip_address',
        'user_agent',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
