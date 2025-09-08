<?php

namespace App\Models\Business;

use App\Models\Business\Trait\Attribute\BusinessTypeAttribute;
use App\Models\Business\Trait\Relationship\BusinessTypeRelationship;
use Illuminate\Database\Eloquent\Model;

class BusinessType extends Model
{
    use BusinessTypeAttribute, BusinessTypeRelationship;
}
