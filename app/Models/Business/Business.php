<?php

namespace App\Models\Business;

use App\Models\Business\Trait\Attribute\BusinessAttribute;
use App\Models\Business\Trait\Relationship\BusinessRelationship;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use BusinessAttribute, BusinessRelationship;
}
