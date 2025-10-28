<?php

namespace App\Models\Business;

use App\Models\Business\Trait\Relationship\BusinessContactRelationship;
use Illuminate\Database\Eloquent\Model;

class BusinessContacts extends Model
{
    use BusinessContactRelationship;
}
