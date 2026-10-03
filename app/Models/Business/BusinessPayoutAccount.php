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
        'business_id', 'gateway', 'wallet_id', 'destination_type', 'provider', 'account_number', 'phone_number',
        'account_holder_name', 'currency', 'country_id', 'verification_status',
        'status', 'is_default', 'verified_by', 'verified_at', 'rejection_reason', 'metadata',
        'verification_document_path', 'verification_document_filename',
        'verification_document_uploaded_by', 'verification_document_uploaded_at',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'verified_at' => 'datetime',
        'verification_document_uploaded_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = ['wallet_id', 'account_number', 'phone_number', 'metadata', 'verification_document_path'];

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

    public function maskedWalletId(): string
    {
        $wallet = (string) ($this->wallet_id ?? '');
        if ($wallet === '') return '';
        if (strlen($wallet) <= 4) return $wallet;
        return str_repeat('•', max(0, strlen($wallet) - 4)) . substr($wallet, -4);
    }

    public function maskedPhoneNumber(): string
    {
        $phone = (string) ($this->phone_number ?: $this->account_number ?? '');
        if ($phone === '') return '';
        if (strlen($phone) <= 4) return $phone;
        return str_repeat('•', max(0, strlen($phone) - 4)) . substr($phone, -4);
    }
}
