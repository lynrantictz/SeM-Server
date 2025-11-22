<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Jobs\Payment\ProcessPaymentWebhook;
use App\Repositories\Payment\PaymentGatewayRepository;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{

    protected $paymentTransactions;

    public function __construct(PaymentTransactionRepository $paymentTransactionRepository)
    {
        $this->paymentTransactions = $paymentTransactionRepository;
    }

    public function handle(Request $request)
    {
        $data = $request->all();
        ProcessPaymentWebhook::dispatch($data);
        return response()->json(['status' => 'ok']);
    }
}
