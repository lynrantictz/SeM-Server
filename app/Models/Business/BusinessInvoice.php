<?php

namespace App\Models\Business;

use App\Models\BaseModel;

class BusinessInvoice extends BaseModel
{
    protected $casts = ['total' => 'decimal:2', 'paid_amount' => 'decimal:2', 'credit_amount' => 'decimal:2', 'period_from' => 'datetime', 'period_to' => 'datetime', 'sent_at' => 'datetime', 'due_at' => 'datetime', 'reminded_at' => 'datetime', 'paid_at' => 'datetime', 'order_count' => 'integer', 'gross_sales' => 'decimal:2', 'order_tax_total' => 'decimal:2', 'commission_base_total' => 'decimal:2', 'rate_breakdown' => 'array', 'issuer_snapshot' => 'array', 'recipient_snapshot' => 'array'];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function items()
    {
        return $this->hasMany(BusinessInvoiceItem::class);
    }

    public function receipts()
    {
        return $this->hasMany(BusinessInvoiceReceipt::class);
    }

    public function summary(): array
    {
        if ($this->order_count !== null) {
            return ['order_count' => $this->order_count, 'gross_sales' => $this->gross_sales, 'order_tax_total' => $this->order_tax_total,
                'commission_base_total' => $this->commission_base_total, 'rate_breakdown' => $this->rate_breakdown ?? [], 'legacy' => false];
        }
        $items = $this->items;

        return ['order_count' => $items->count(), 'gross_sales' => null, 'order_tax_total' => null,
            'commission_base_total' => number_format($items->sum(fn ($item) => (int) round((float) $item->base_amount * 100)) / 100, 2, '.', ''),
            'rate_breakdown' => $items->groupBy(fn ($item) => (string) $item->rate)->map(fn ($rows, $rate) => [
                'rate' => (float) $rate, 'reason' => 'historical_snapshot', 'order_count' => $rows->count(),
                'base_amount' => $rows->sum(fn ($item) => (int) round((float) $item->base_amount * 100)) / 100,
                'fee_amount' => $rows->sum(fn ($item) => (int) round((float) $item->amount * 100)) / 100,
            ])->values()->all(), 'legacy' => true];
    }

    public function outstanding(): float
    {
        return max(0, round((float) $this->total - (float) $this->paid_amount - (float) $this->credit_amount, 2));
    }
}
