<?php

namespace App\Services\PaymentGateway;

use App\Models\Business\BusinessPayout;
use App\Models\Business\BusinessPayoutAccount;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentAllocation;

class BusinessPayoutLedgerService
{
    public function createFromAllocation(Payment $payment, PaymentAllocation $allocation): BusinessPayout
    {
        $account = BusinessPayoutAccount::query()
            ->where('business_id', $allocation->business_id)
            ->where('is_default', true)
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->first();

        return BusinessPayout::query()->firstOrCreate(
            ['payment_id' => $payment->id, 'business_id' => $allocation->business_id],
            [
                'payment_allocation_id' => $allocation->id,
                'payout_account_id' => $account?->id,
                'gateway' => 'azampay',
                'gross_amount' => $allocation->gross_amount,
                'commission_amount' => $allocation->commission_amount,
                'gateway_fee_amount' => $allocation->gateway_fee_amount,
                'net_amount' => $allocation->business_payable_amount,
                'currency' => $allocation->currency,
                'status' => 'pending_review',
                'idempotency_key' => 'PAYOUT-' . $payment->uuid,
                'hold_reason' => $account
                    ? 'Awaiting Paperstic payout processing.'
                    : 'Awaiting a verified default business payout account.',
            ],
        );
    }
}
