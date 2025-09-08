<?php

namespace App\Models\Payment;

use App\Models\Payment\Trait\Attribute\PaymentGatewayAttribute;
use App\Models\Payment\Trait\Relationship\PaymentGatewayRelationship;
use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    use PaymentGatewayAttribute, PaymentGatewayRelationship;
}
