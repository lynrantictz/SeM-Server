<?php

namespace App\Services;

use App\Models\Location\Country;

trait TaxCalculatorService
{
    public function calculateTax(Country $country, $total_items_amount, bool $taxAllowed = true)
    {
        $tax_id = NULL;
        $tax_amount = 0;
        // Tax is applied only after the business is verified as VAT registered.
        $tax = $taxAllowed
            ? $country->taxes()->where('is_active', true)->first()
            : null;
        if (!$tax) {
            $total_amount = $total_items_amount;
        } else {
            $tax_id = $tax->id;
            $percentage = $tax->percent;
            $tax_amount = $total_items_amount * ($percentage / 100);
            $total_amount = $tax_amount + $total_items_amount;
        }

        return [
            'tax_id' => $tax_id,
            'total_items_amount' => $total_items_amount,
            'tax_amount' => $tax_amount,
            'total_amount' => $total_amount
        ];
    }
}
