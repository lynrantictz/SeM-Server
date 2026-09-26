<?php

namespace App\Services\PaymentGateway;

use App\Models\Business\BusinessPaymentSetting;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentEvent;
use App\Models\Payment\PaymentMethod;
use App\Models\Payment\PaymentStatus;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentSettlementService
{
    /**
     * Creates a local attempt only when the gateway request did not create one,
     * then settles it as a sandbox demonstration without contacting AzamPay.
     */
    public function completeSandboxDemoForOrder(
        Order $order,
        string $accountNumber,
        string $provider,
        ?int $initiatedByUserId = null,
        string $initiationSource = 'customer',
    ): Payment {
        $payment = DB::transaction(function () use ($order, $accountNumber, $provider, $initiatedByUserId, $initiationSource): Payment {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = Payment::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', ['PENDING', 'PROCESSING'])
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            $amount = (float) ($lockedOrder->due_amount ?: $lockedOrder->total_amount);
            if ($amount <= 0) {
                throw new RuntimeException('This order has no outstanding amount to pay.');
            }

            return Payment::query()->create([
                'order_id' => $lockedOrder->id,
                'initiated_by_user_id' => $initiatedByUserId,
                'external_id' => 'PST-DEMO-' . Str::upper(Str::random(12)),
                'idempotency_key' => (string) Str::uuid(),
                'account_number' => $accountNumber,
                'provider' => 'azampay',
                'initiation_source' => $initiationSource,
                'amount' => $amount,
                'currency' => 'TZS',
                'status' => 'PENDING',
                'expires_at' => now()->addMinutes(5),
                'request_payload' => [
                    'account_number' => $accountNumber,
                    'provider' => $provider,
                    'mode' => 'sandbox_demo',
                ],
                'metadata' => [
                    'order_number' => $lockedOrder->number,
                    'business_id' => $lockedOrder->business_id,
                    'mode' => 'sandbox_demo',
                ],
            ]);
        });

        return $this->completeSandboxDemo($payment);
    }

    /**
     * Completes a sandbox-only demo through the same order and allocation
     * records used by a verified gateway callback.
     */
    public function completeSandboxDemo(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment): Payment {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($lockedPayment->status === 'SUCCESS') {
                return $lockedPayment;
            }

            if (! in_array($lockedPayment->status, ['PENDING', 'PROCESSING'], true)) {
                throw new RuntimeException('This payment attempt can no longer be completed as a demo.');
            }

            $event = PaymentEvent::query()->firstOrCreate([
                'provider' => 'paperstic_sandbox',
                'event_reference' => "sandbox-demo-{$lockedPayment->uuid}",
            ], [
                'payment_id' => $lockedPayment->id,
                'event_type' => 'sandbox.demo.completed',
                'signature_verified' => true,
                'payload' => ['mode' => 'sandbox_demo'],
                'received_at' => now(),
            ]);

            $lockedPayment->update([
                'transaction_id' => $lockedPayment->transaction_id ?: 'DEMO-' . Str::upper(Str::random(14)),
                'operator' => 'SANDBOX_DEMO',
                'status' => 'SUCCESS',
                'paid_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'confirmed_at' => now(),
                'confirmation_source' => 'sandbox_demo',
                'response_payload' => ['mode' => 'sandbox_demo', 'completed_at' => now()->toIso8601String()],
            ]);

            $this->completeOrderPayment($lockedPayment);
            $event->update(['processed_at' => now()]);

            return $lockedPayment->fresh();
        });
    }

    /** This method must be called within the transaction that locks the payment. */
    public function completeOrderPayment(Payment $payment): void
    {
        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
        $mobileMoney = PaymentMethod::query()->firstOrCreate(['name' => 'Mobile Money']);
        $completed = PaymentStatus::query()->where('name', 'Completed')->firstOrFail();
        $order->update([
            'payment_method_id' => $mobileMoney->id,
            'payment_status_id' => $completed->id,
            'paid_amount' => $payment->amount,
            'due_amount' => 0,
        ]);

        $setting = BusinessPaymentSetting::query()->firstOrCreate(
            ['business_id' => $order->business_id],
            [
                'provider' => 'azampay',
                'currency' => $payment->currency,
                'commission_basis' => 'subtotal_excluding_tax',
                'fee_bearer' => 'business',
                'settlement_mode' => 'manual_hold',
            ],
        );
        $commissionBase = $setting->commission_basis === 'subtotal_excluding_tax'
            ? (float) $order->total_items_amount
            : (float) $payment->amount;
        $commissionRate = $setting->commission_rate ?? SystemSetting::valueFor('payments.default_commission_rate');
        $commission = round($commissionBase * ((float) $commissionRate / 100), 2);

        PaymentAllocation::query()->firstOrCreate(['payment_id' => $payment->id], [
            'business_id' => $order->business_id,
            'gross_amount' => $payment->amount,
            'commission_base_amount' => $commissionBase,
            'commission_rate' => $commissionRate,
            'commission_amount' => $commission,
            'gateway_fee_amount' => 0,
            'business_payable_amount' => round((float) $payment->amount - $commission, 2),
            'currency' => $payment->currency,
            'calculation' => [
                'basis' => $setting->commission_basis,
                'fee_bearer' => $setting->fee_bearer,
                'settlement_mode' => $setting->settlement_mode,
            ],
        ]);
    }
}
