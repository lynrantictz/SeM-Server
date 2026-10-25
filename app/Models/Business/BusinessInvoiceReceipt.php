<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class BusinessInvoiceReceipt extends BaseModel
{
    protected $casts = ['amount' => 'decimal:2'];
}
