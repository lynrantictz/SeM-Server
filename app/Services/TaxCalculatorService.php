<?php

namespace App\Services;

use App\Models\Location\Country;

trait TaxCalculatorService
{
    public function calculateTax(Country $country, $total_items_amount, bool $taxAllowed = true)
    {
        $tax_id = NULL;
        $tax_amount = 0;
        $tax_rate = null;
        // Tax is applied only after the business is verified as VAT registered.
        $tax = $taxAllowed
            ? $country->taxes()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('effective_from')->orWhere('effective_from', '<=', now());
                })
                ->where(function ($query): void {
                    $query->whereNull('effective_until')->orWhere('effective_until', '>', now());
                })
                ->orderByDesc('effective_from')
                ->first()
            : null;
        if (!$tax) {
            $total_amount = $total_items_amount;
        } else {
            $tax_id = $tax->id;
            $percentage = $tax->percent;
            $tax_rate = $percentage;
            $tax_amount = $total_items_amount * ($percentage / 100);
            $total_amount = $tax_amount + $total_items_amount;
        }

        return [
            'tax_id' => $tax_id,
            'tax_rate' => $tax_rate,
            'total_items_amount' => $total_items_amount,
            'tax_amount' => $tax_amount,
            'total_amount' => $total_amount
        ];
    }
}
