<?php

namespace App\Models\Transaction;

use App\Models\BaseModel;
use App\Models\Transaction\Trait\Attribute\RefundAttribute;
use App\Models\Transaction\Trait\Relationship\RefundRelationship;
use Illuminate\Database\Eloquent\Model;

class Refund extends BaseModel
{
    use RefundAttribute, RefundRelationship;
}
