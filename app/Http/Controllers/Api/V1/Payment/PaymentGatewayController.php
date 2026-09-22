<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Api\BaseController;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Services\PaymentGateway\PaymentCheckoutService;
use Illuminate\Http\Request;

class PaymentGatewayController extends BaseController
{
    public function __construct(private readonly PaymentCheckoutService $checkout)
    {
    }

    public function checkout(Request $request, string $order)
    {
        $validated = $request->validate([
            'phone' => ['required', 'regex:/^[1-9][0-9]{6,14}$/'],
            'provider' => ['required'],
        ]);

        $record = Order::query()->where('number', $order)->firstOrFail();
        $checkout = $this->checkout->initiateMnoCheckout(
            $record,
            $validated['phone'],
            $validated['provider'],
            auth()->id(),
            auth()->check() ? 'staff' : 'customer',
        );

        return $this->sendResponse([
            'payment' => [
                ...$this->paymentData($checkout->payment),
                'prompt_sent' => $checkout->promptSent,
                'awaiting_gateway_confirmation' => $checkout->awaitingGatewayConfirmation,
            ],
        ], 'Mobile money prompt initiated.');
    }

    public function status(string $order)
    {
        $record = Order::query()->where('number', $order)->firstOrFail();
        $payment = Payment::query()->where('order_id', $record->id)->latest('id')->first();

        return $this->sendResponse(['payment' => $payment ? $this->paymentData($payment) : null], 'Payment status retrieved.');
    }

    private function paymentData(Payment $payment): array
    {
        return [
            'uuid' => $payment->uuid,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'failure_reason' => $payment->failure_reason,
        ];
    }
}
