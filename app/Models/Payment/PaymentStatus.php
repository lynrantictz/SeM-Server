<?php

namespace App\Models\Payment;

use App\Models\Payment\Trait\Attribute\PaymentStatusAttribute;
use App\Models\Payment\Trait\Relationship\PaymentStatusRelationship;
use Illuminate\Database\Eloquent\Model;

class PaymentStatus extends Model
{
    use PaymentStatusAttribute, PaymentStatusRelationship;
}
