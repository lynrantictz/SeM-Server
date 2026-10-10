<?php

namespace App\Models\Payment;

use App\Models\Business\BusinessPaymentMethod;
use App\Models\Location\Country;
use App\Models\Payment\Trait\Attribute\PaymentMethodAttribute;
use App\Models\Payment\Trait\Relationship\PaymentMethodRelationship;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use PaymentMethodAttribute, PaymentMethodRelationship;

    protected $fillable = [
        'name', 'code', 'country_id', 'identifier_label', 'instructions', 'logo_path',
        'requires_identifier', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'requires_identifier' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function country(): BelongsTo { return $this->belongsTo(Country::class); }
    public function businessPaymentMethods(): HasMany { return $this->hasMany(BusinessPaymentMethod::class); }
}
