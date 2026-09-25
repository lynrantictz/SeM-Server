<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\SystemSetting;
use App\Models\Business\BusinessPaymentSetting;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentAllocation;
use App\Models\Payment\PaymentEvent;
use App\Models\Payment\PaymentMethod;
use App\Models\Payment\PaymentStatus;
use App\Services\PaymentGateway\Providers\AzamPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentWebhookController extends Controller
{
    public function __construct(private readonly AzamPayService $azamPay) {}

    public function handle(Request $request)
    {
        $data = $request->validate([
            'transactionstatus' => ['required', 'string'],
            'utilityref' => ['required', 'string'],
            'externalreference' => ['required', 'string'],
            'operator' => ['required', 'string'],
            'amount' => ['required'],
            'transid' => ['required', 'string'],
            'msisdn' => ['required', 'string'],
            'signature' => ['nullable', 'string'],
        ]);

        $signatureVerified = false;
        try {
            $signatureVerified = $this->azamPay->verifyCallbackSignature($request->all());
        } catch (\Throwable) {
            $signatureVerified = false;
        }

        if (config('payments.azampay.require_callback_signature') && ! $signatureVerified) {
            throw ValidationException::withMessages(['signature' => 'Invalid payment callback signature.']);
        }

        DB::transaction(function () use ($request, $data, $signatureVerified): void {
            $payment = Payment::query()->where('external_id', $data['utilityref'])->lockForUpdate()->firstOrFail();
            $event = PaymentEvent::query()->firstOrCreate([
                'provider' => 'azampay',
                'event_reference' => $data['transid'],
            ], [
                'payment_id' => $payment->id,
                'event_type' => 'checkout.callback',
                'signature_verified' => $signatureVerified,
                'payload' => Arr::except($request->all(), ['password', 'signature']),
                'received_at' => now(),
            ]);

            if ($event->processed_at) {
                return;
            }

            // A staff member may have corrected the guest's number or provider
            // and replaced this attempt. Record the callback, but never allow a
            // late approval for the old prompt to settle the order.
            if (str_starts_with((string) $payment->failure_reason, 'Superseded by a replacement')) {
                $event->update(['processed_at' => now()]);

                return;
            }

            $amountMatches = round((float) $payment->amount, 2) === round((float) $data['amount'], 2);
            $successful = strtolower($data['transactionstatus']) === 'success' && $amountMatches;
            $safePayload = Arr::except($request->all(), ['password', 'signature']);

            $payment->update([
                'transaction_id' => $data['transid'],
                'operator' => $data['operator'],
                'mno_reference' => $request->input('mnoreference'),
                'utility_ref' => $data['utilityref'],
                'msisdn' => $data['msisdn'],
                'message' => $request->input('message'),
                'status' => $successful ? 'SUCCESS' : 'FAILED',
                'paid_at' => $successful ? now() : null,
                'failed_at' => $successful ? null : now(),
                'failure_reason' => $successful ? null : ($amountMatches ? 'Payment was not successful.' : 'Callback amount does not match the payment attempt.'),
                'confirmed_at' => $successful ? now() : null,
                'confirmation_source' => $successful ? 'gateway_callback' : null,
                'response_payload' => $safePayload,
            ]);

            if ($successful) {
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

            $event->update(['processed_at' => now()]);
        });

        return response()->json(['status' => 'ok']);
    }
}
