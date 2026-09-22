<?php

namespace App\Services\PaymentGateway;

use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Services\PaymentGateway\Data\PaymentCheckoutResult;
use App\Services\PaymentGateway\Providers\AzamPayService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
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
                return $existing;
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

            $updatedPayment = DB::transaction(function () use ($payment, $response, $successful): Payment {
                $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                $lockedPayment->update([
                    'transaction_id' => data_get($response, 'transactionId') ?? data_get($response, 'data.transactionId'),
                    'status' => $successful ? 'PROCESSING' : 'FAILED',
                    'failed_at' => $successful ? null : now(),
                    'failure_reason' => $successful ? null : (string) (data_get($response, 'message') ?? 'Checkout request rejected.'),
                    'response_payload' => $response,
                ]);

                return $lockedPayment->fresh();
            });

            return new PaymentCheckoutResult($updatedPayment, $successful);
        } catch (ConnectionException $exception) {
            $pendingPayment = DB::transaction(function () use ($payment): Payment {
                $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                $lockedPayment->update([
                    'status' => 'PENDING',
                    'failure_reason' => 'Awaiting gateway confirmation after a checkout timeout.',
                ]);

                return $lockedPayment->fresh();
            });

            return new PaymentCheckoutResult($pendingPayment, false, true);
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($payment): void {
                Payment::query()->lockForUpdate()->findOrFail($payment->id)->update([
                    'status' => 'FAILED',
                    'failed_at' => now(),
                    'failure_reason' => 'Unable to initiate mobile money checkout.',
                ]);
            });

            throw $exception;
        }
    }
}
