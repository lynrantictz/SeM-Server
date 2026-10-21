<?php

namespace App\Models\Location;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Auditable;

class Tax extends Model implements AuditableContract
{
    use Auditable;

    protected $guarded = [];

    protected $auditInclude = [
        'country_id',
        'percent',
        'is_active',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'percent' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
