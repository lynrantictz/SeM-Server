<?php

namespace App\Models\Customer;


use App\Models\BaseModel;
use App\Models\Customer\Trait\Attribute\CustomerAttribute;
use App\Models\Customer\Trait\Relationship\CustomerRelationship;

class Customer extends BaseModel
{
    use CustomerAttribute, CustomerRelationship;

    protected $hidden = [
        'created_at',
        'updated_at',
        'phone_e164',
    ];

    /**
     * Expose the canonical value while retaining the legacy column for
     * existing records and rollback-safe migrations.
     */
    public function getPhoneAttribute($value): ?string
    {
        return $this->attributes['phone_e164'] ?? $value;
    }
}
