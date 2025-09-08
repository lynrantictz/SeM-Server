<?php

namespace App\Models\Business;

use App\Models\BaseModel;
use App\Models\Business\Trait\Attribute\VendorAttribute;
use App\Models\Business\Trait\Relationship\VendorRelationship;

class Vendor extends BaseModel
{
    use VendorAttribute, VendorRelationship;
}
