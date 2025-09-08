<?php

namespace App\Models\Customer;


use App\Models\BaseModel;
use App\Models\Customer\Trait\Attribute\CustomerAttribute;
use App\Models\Customer\Trait\Relationship\CustomerRelationship;

class Customer extends BaseModel
{
    use CustomerAttribute, CustomerRelationship;
}
