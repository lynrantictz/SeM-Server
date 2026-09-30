<?php

namespace App\Models\Business;

use App\Models\Auth\User;
use App\Models\BaseModel;
use App\Models\Location\Country;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessPayoutAccount extends BaseModel
{
    protected $fillable = [
        'business_id', 'gateway', 'destination_type', 'provider', 'account_number',
        'account_holder_name', 'currency', 'country_id', 'verification_status',
        'status', 'is_default', 'verified_by', 'verified_at', 'rejection_reason', 'metadata',
    ];

    protected $casts = [
        'account_number' => 'encrypted',
        'is_default' => 'boolean',
        'verified_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = ['account_number', 'metadata'];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function country(): BelongsTo { return $this->belongsTo(Country::class); }
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    public function payouts(): HasMany { return $this->hasMany(BusinessPayout::class, 'payout_account_id'); }

    public function maskedAccountNumber(): string
    {
        $number = (string) ($this->account_number ?? '');
        if (strlen($number) <= 4) return $number;
        return str_repeat('•', max(0, strlen($number) - 4)) . substr($number, -4);
    }
}
