<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessOnboardingPayment extends BaseModel
{
    protected $fillable = [
        'business_id', 'package_id', 'package', 'amount_due', 'amount_paid', 'currency',
        'status', 'payment_method', 'payment_reference', 'paid_at',
        'proof_path', 'proof_filename', 'uploaded_by', 'verified_by',
        'verified_at', 'notes',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function onboardingPackage(): BelongsTo { return $this->belongsTo(OnboardingPackage::class, 'package_id'); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
}
