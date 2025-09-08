<?php

namespace App\Models\Payment;

use App\Models\Payment\Trait\Attribute\PaymentMethodAttribute;
use App\Models\Payment\Trait\Relationship\PaymentMethodRelationship;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use PaymentMethodAttribute, PaymentMethodRelationship;
}
