<?php

namespace App\Jobs\Payment;

use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public array $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $repo = new PaymentTransactionRepository();

        $transaction = $repo->findByTransactionId($this->payload['transactionId']);

        if (!$transaction) {
            return;
        }
        DB::transaction(function () use ($repo, $transaction) {
            if ($this->payload['success'] === true) {
                $repo->updateStatus($transaction->id, 'SUCCESS');
            } else {
                $repo->updateStatus($transaction->id, 'FAILED');
            }
        });
    }
}
