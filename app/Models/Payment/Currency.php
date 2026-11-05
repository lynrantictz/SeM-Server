<?php

namespace App\Models\Payment;

use App\Models\Location\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Currency extends Model
{
    protected $fillable = ['code', 'name', 'symbol', 'minor_unit', 'is_active', 'sort_order'];

    protected $casts = [
        'minor_unit' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'country_currencies')
            ->withPivot(['is_default', 'is_active'])
            ->withTimestamps();
    }
}
