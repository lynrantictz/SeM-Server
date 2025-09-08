<?php

namespace App\Models\Transaction;

use App\Models\BaseModel;
use App\Models\Transaction\Trait\Attribute\TransactionAttribute;
use App\Models\Transaction\Trait\Relationship\TransactionRelationship;
use Illuminate\Database\Eloquent\Model;

class Transaction extends BaseModel
{
    use TransactionAttribute, TransactionRelationship;
}
