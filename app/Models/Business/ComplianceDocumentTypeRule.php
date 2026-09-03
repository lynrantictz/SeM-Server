<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use App\Models\Location\Country;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceDocumentTypeRule extends BaseModel
{
    protected $fillable = [
        'compliance_document_type_id',
        'country_id',
        'business_type_id',
        'required_for_payment_activation',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'required_for_payment_activation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocumentType::class, 'compliance_document_type_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }
}
