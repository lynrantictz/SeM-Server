<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use App\Models\BaseModel;
use App\Models\Payment\PaymentMethod;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessPaymentMethod extends BaseModel
{
    protected $fillable = [
        'business_id',
        'payment_method_id',
        'identifier',
        'bank_name',
        'account_holder_name',
        'status',
        'is_enabled',
        'sort_order',
        'verified_by_user_id',
        'verified_at',
        'rejection_reason',
        'metadata',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(BusinessPaymentMethodAccount::class)->orderBy('sort_order')->orderBy('id');
    }
}
