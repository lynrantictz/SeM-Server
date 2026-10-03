<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingPackage extends BaseModel
{
    protected $fillable = ['key', 'name', 'price', 'currency', 'is_active', 'sort_order'];

    protected $casts = ['price' => 'decimal:2', 'is_active' => 'boolean'];

    public function payments(): HasMany
    {
        return $this->hasMany(BusinessOnboardingPayment::class, 'package_id');
    }
}
