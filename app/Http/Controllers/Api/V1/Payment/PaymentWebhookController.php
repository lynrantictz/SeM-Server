<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentEvent;
use App\Services\PaymentGateway\PaymentSettlementService;
use App\Services\PaymentGateway\Providers\AzamPayService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly AzamPayService $azamPay,
        private readonly PaymentSettlementService $settlements,
    ) {}

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
                $this->settlements->completeOrderPayment($payment);
            }

            $event->update(['processed_at' => now()]);
        });

        return response()->json(['status' => 'ok']);
    }
}
