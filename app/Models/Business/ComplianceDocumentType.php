<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceDocumentType extends BaseModel
{
    protected $fillable = [
        'key',
        'name',
        'scope',
        'description',
        'requires_expiry_date',
        'reminder_days',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_expiry_date' => 'boolean',
            'reminder_days' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ComplianceDocumentTypeRule::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ComplianceDocument::class);
    }
}
