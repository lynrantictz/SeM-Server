<?php

namespace App\Services;

use App\Models\Location\Country;

trait TaxCalculatorService
{
    public function calculateTax(Country $country, $total_amount)
    {
        $tax_id = NULL;
        $tax_amount = 0;
        //check if there active tax
        $tax = $country->tax->where('is_active', true)->first();
        if ($tax) {
            $tax_id = $tax->id;
            $percentage = $tax->percent;
            $tax_amount = $total_amount * ($percentage / 100);
            $total_amount = $tax_amount + $total_amount;
        }

        return [
            'tax_id' => $tax_id,
            'tax_amount' => $tax_amount,
            'total_amount' => $total_amount
        ];
    }
}
