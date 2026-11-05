<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingPackage extends BaseModel
{
    protected $fillable = ['key', 'name', 'price', 'currency', 'is_active', 'sort_order', 'version', 'activated_at', 'deactivated_at'];

    protected $casts = ['price' => 'decimal:2', 'is_active' => 'boolean', 'version' => 'integer', 'activated_at' => 'datetime', 'deactivated_at' => 'datetime'];

    public function payments(): HasMany
    {
        return $this->hasMany(BusinessOnboardingPayment::class, 'package_id');
    }
}
