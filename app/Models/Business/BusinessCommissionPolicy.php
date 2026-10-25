<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class BusinessCommissionPolicy extends BaseModel
{
    protected $casts = ['rate' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'approved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $policy) {
            if ($policy->getOriginal('state') !== 'draft') {
                if ($policy->isDirty('state')) {
                    abort_unless($policy->state === 'cancelled' && $policy->starts_at->isFuture() && ! $policy->fees()->exists(), 409, 'Only unused future agreements can be cancelled.');
                }
                $forbidden = array_diff(array_keys($policy->getDirty()), ['ends_at', 'state', 'cancellation_reason', 'updated_at']);
                abort_if(count($forbidden) > 0, 409, 'Published commission agreements are immutable. Create a replacement.');
            }
        });
        static::deleting(fn (self $policy) => abort_if($policy->state !== 'draft' || $policy->fees()->exists(), 409, 'Published or referenced commission agreements cannot be deleted.'));
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function fees()
    {
        return $this->hasMany(OrderPlatformFee::class);
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\Auth\User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Models\Auth\User::class, 'approved_by');
    }

    public function getStatusAttribute(): string
    {
        if ($this->state !== 'published') {
            return $this->state;
        }
        if ($this->starts_at->isFuture()) {
            return 'scheduled';
        }

        return $this->ends_at && $this->ends_at->lte(now()) ? 'ended' : 'effective';
    }
}
