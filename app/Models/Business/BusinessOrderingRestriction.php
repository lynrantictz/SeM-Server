<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class BusinessOrderingRestriction extends BaseModel
{
    protected $casts = ['restricted_at' => 'datetime', 'observed_at' => 'datetime', 'restored_at' => 'datetime', 'invoice_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $event) {
            abort_if($event->isDirty(['business_id', 'restricted_at', 'observed_at', 'date_basis', 'reason', 'invoice_snapshot', 'recorded_by']), 409, 'Restriction history cannot be rewritten.');
            abort_if($event->getOriginal('restored_at') !== null && $event->isDirty(), 409, 'A restored restriction is a permanent history record.');
            abort_if($event->isDirty('active_business_id') && $event->active_business_id !== null, 409, 'Create a new restriction episode instead.');
        });
        static::deleting(fn () => abort(409, 'Restriction history cannot be deleted.'));
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
