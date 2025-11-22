<?php

namespace App\Jobs\Payment;

use App\Models\Payment\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public array $payload;
    public PaymentTransaction $paymentTransaction;

    /**
     * Create a new job instance.
     */
    public function __construct(PaymentTransaction $paymentTransaction, array $payload)
    {
        $this->payload = $payload;
        $this->paymentTransaction = $paymentTransaction;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {

        DB::transaction(function () {
            $this->paymentTransaction->update([
                'operator'       => $this->payload['operator'],
                'client_id'      => $this->payload['clientId'],
                'mno_reference'  => $this->payload['mnoreference'],
                'utility_ref'    => $this->payload['utilityref'],
                'msisdn'         => $this->payload['msisdn'],
                'message'        => $this->payload['message'],
                'response_payload' => $this->payload,
            ]);

            // Map AzamPay status → local status
            $status = strtolower($data['transactionstatus']) === 'success'
                ? 'SUCCESS'
                : 'FAILED';

            $repo->updateStatus($transaction->id, $status);
        });
    }
}
