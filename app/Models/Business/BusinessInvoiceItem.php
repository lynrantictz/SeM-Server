<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class BusinessInvoiceItem extends BaseModel
{
    protected $casts = ['base_amount' => 'decimal:2', 'rate' => 'decimal:2', 'amount' => 'decimal:2'];
}
