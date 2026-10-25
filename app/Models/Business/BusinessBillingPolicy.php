<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class BusinessBillingPolicy extends BaseModel
{
    protected $casts = ['automatic_invoicing' => 'boolean', 'enforce_overdue' => 'boolean', 'restriction_enabled_at' => 'datetime', 'trial_started_at' => 'datetime', 'fee_starts_at' => 'datetime', 'next_invoice_at' => 'datetime', 'last_invoice_at' => 'datetime'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
