<?php

namespace App\Repositories\Payment;

use App\Models\Payment\PaymentTransaction;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;

class PaymentTransactionRepository extends BaseRepository
{

    // const MODEL = PaymentTransaction::class;

    // public function create(array $data): PaymentTransaction
    // {
    //     return DB::transaction(function () use ($data) {
    //         return $this->query()->create($data);
    //     });
    // }

    // public function updateStatus(PaymentTransaction $paymentTransaction, string $status): PaymentTransaction
    // {
    //     return DB::transaction(function () use ($paymentTransaction, $status) {
    //         $paymentTransaction->update(['status' => $status]);
    //         return $paymentTransaction;
    //     });
    // }

    // public function updateAzamPayId(PaymentTransaction $paymentTransaction, string $txnId)
    // {
    //     return DB::transaction(function () use ($paymentTransaction, $txnId) {
    //         $paymentTransaction->update([
    //             'transaction_id' => $txnId
    //         ]);
    //     });
    // }

    // public function findByTransactionId(string $txnId): ?PaymentTransaction
    // {
    //     return $this->query()->where('transaction_id', $txnId)->first();
    // }
}
