<?php

namespace App\Services\PaymentGateway;

use App\Jobs\Payments\InitiateAzamPayMnoCheckout;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Services\PaymentGateway\Data\PaymentCheckoutResult;
use App\Services\PaymentGateway\Providers\AzamPayService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentCheckoutService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function initiateMnoCheckout(
        Order $order,
        string $accountNumber,
        int|string $provider,
        ?int $initiatedByUserId = null,
        string $initiationSource = 'customer',
    ): PaymentCheckoutResult {
        $shouldRequestCheckout = false;
        $payment = DB::transaction(function () use ($order, $accountNumber, $provider, $initiatedByUserId, $initiationSource, &$shouldRequestCheckout): Payment {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = Payment::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', ['PENDING', 'PROCESSING'])
                ->where(fn($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')
                ->first();

            if ($existing) {
                $existingProvider = (string) data_get($existing->request_payload, 'provider');
                $samePaymentDetails = $existing->account_number === $accountNumber
                    && $existingProvider === (string) $provider;

                if ($samePaymentDetails) {
                    return $existing;
                }

                // A staff member may correct a guest's number or network without
                // waiting for expiry. Keep the attempt for audit purposes, but do
                // not let a late callback for the replaced prompt settle the order.
                $existing->update([
                    'status' => 'FAILED',
                    'failed_at' => now(),
                    'failure_reason' => 'Superseded by a replacement mobile-money payment request.',
                ]);
            }

            $amount = (float) ($lockedOrder->due_amount ?: $lockedOrder->total_amount);
            if ($amount <= 0) {
                throw new RuntimeException('This order has no outstanding amount to pay.');
            }

            $shouldRequestCheckout = true;

            return Payment::query()->create([
                'order_id' => $lockedOrder->id,
                'initiated_by_user_id' => $initiatedByUserId,
                'external_id' => 'PST-' . Str::upper(Str::random(16)),
                'idempotency_key' => (string) Str::uuid(),
                'account_number' => $accountNumber,
                'provider' => 'azampay',
                'initiation_source' => $initiationSource,
                'amount' => $amount,
                'currency' => 'TZS',
                'status' => 'PENDING',
                'expires_at' => now()->addMinutes(5),
                'request_payload' => ['account_number' => $accountNumber, 'provider' => $provider],
                'metadata' => ['order_number' => $lockedOrder->number, 'business_id' => $lockedOrder->business_id],
            ]);
        });

        if (! $shouldRequestCheckout) {
            return new PaymentCheckoutResult($payment, false);
        }

        InitiateAzamPayMnoCheckout::dispatch($payment->id)->onQueue('payments');

        return new PaymentCheckoutResult($payment, false, true, true);
    }

    /**
     * Runs only in the payments queue. Keeping the external gateway call out of
     * the HTTP request prevents a slow provider from terminating the browser
     * request or holding a staff member's screen hostage.
     */
    public function sendQueuedMnoCheckout(int $paymentId): void
    {
        $payment = Payment::query()->find($paymentId);

        if (! $payment || ! in_array($payment->status, ['PENDING', 'PROCESSING'], true)
            || ($payment->expires_at && $payment->expires_at->isPast())) {
            return;
        }

        $accountNumber = (string) $payment->account_number;
        $provider = data_get($payment->request_payload, 'provider');
        if ($accountNumber === '' || ! is_string($provider) || $provider === '') {
            $this->markCheckoutFailed($payment->id, 'Payment request is missing mobile-money details.');

            return;
        }

        try {
            /** @var AzamPayService $gateway */
            $gateway = $this->gateways->gateway('azampay');
            $response = $gateway->mnoCheckout([
                'accountNumber' => $accountNumber,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'externalId' => $payment->external_id,
                'provider' => $provider,
                'additionalProperties' => ['orderNumber' => data_get($payment->metadata, 'order_number')],
            ]);
            $successful = data_get($response, 'success', false) === true;

            DB::transaction(function () use ($payment, $response, $successful): void {
                $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                if (! in_array($lockedPayment->status, ['PENDING', 'PROCESSING'], true)) {
                    return;
                }

                $lockedPayment->update([
                    'transaction_id' => data_get($response, 'transactionId') ?? data_get($response, 'data.transactionId'),
                    'status' => $successful ? 'PROCESSING' : 'FAILED',
                    'failed_at' => $successful ? null : now(),
                    'failure_reason' => $successful ? null : (string) (data_get($response, 'message') ?? 'Checkout request rejected.'),
                    'response_payload' => $response,
                ]);
            });
        } catch (ConnectionException $exception) {
            DB::transaction(function () use ($payment): void {
                $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                if (! in_array($lockedPayment->status, ['PENDING', 'PROCESSING'], true)) {
                    return;
                }

                $lockedPayment->update([
                    'status' => 'PENDING',
                    'failure_reason' => 'Awaiting gateway confirmation after a checkout timeout.',
                ]);
            });
        } catch (\Throwable $exception) {
            Log::error('AzamPay MNO checkout job failed.', [
                'payment_id' => $payment->id,
                'external_id' => $payment->external_id,
                'exception' => $exception,
            ]);
            $reason = str_starts_with($exception->getMessage(), 'AzamPay ')
                ? str($exception->getMessage())->limit(500, '…')->toString()
                : 'Unable to initiate mobile money checkout.';
            $this->markCheckoutFailed($payment->id, $reason);
        }
    }

    private function markCheckoutFailed(int $paymentId, string $reason): void
    {
        DB::transaction(function () use ($paymentId, $reason): void {
            $payment = Payment::query()->lockForUpdate()->find($paymentId);
            if (! $payment || ! in_array($payment->status, ['PENDING', 'PROCESSING'], true)) {
                return;
            }

            $payment->update([
                'status' => 'FAILED',
                'failed_at' => now(),
                'failure_reason' => $reason,
            ]);
        });
    }
}
