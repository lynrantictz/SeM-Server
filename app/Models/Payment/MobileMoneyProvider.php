<?php

namespace App\Models\Payment;

use App\Models\BaseModel;
use App\Models\Location\Country;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileMoneyProvider extends BaseModel
{
    protected $fillable = [
        'country_id', 'gateway', 'code', 'name', 'logo_url', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
