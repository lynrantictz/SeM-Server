<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Jobs\Payment\ProcessPaymentWebhook;
use App\Repositories\Payment\PaymentGatewayRepository;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{

    protected $paymentTransactions;

    public function __construct(PaymentTransactionRepository $paymentTransactionRepository)
    {
        $this->paymentTransactions = $paymentTransactionRepository;
    }

    public function handle(Request $request)
    {
        Log::info('AzamPay Callback Received', $request->all());
        $data = $request->all();
        $transaction = $this->paymentTransactions->findByTransactionId($data['transid']);
        if (!$transaction) {
            Log::warning('Callback ignored — transaction not found', ['transid' => $data['transid']]);
            return response()->json(['status' => 'ignored'], 404);
        }
        ProcessPaymentWebhook::dispatch($transaction, $data);
        return response()->json(['status' => 'ok'], 200);
    }
}
