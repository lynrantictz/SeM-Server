<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Api\BaseController;
use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Repositories\Payment\PaymentGatewayRepository;
use App\Services\PaymentGateway\DTOs\MnoCheckoutData;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Http\Request;

class PaymentGatewayController extends BaseController
{
    protected PaymentGatewayRepository $paymentGateways;
    protected PaymentGatewayManager $paymentGatewayManager;

    public function __construct(PaymentGatewayRepository $paymentGateways, PaymentGatewayManager $paymentGatewayManager)
    {
        $this->paymentGateways = $paymentGateways;
        $this->paymentGatewayManager = $paymentGatewayManager;
    }

    /**
     * Display a listing of the resource.
     */
    public function checkout(Request $request, Order $order)
    {
        // $dto = new MnoCheckoutData(
        //     accountNumber: $request['phone'],
        //     amount: $order->total_amount,
        //     currency: 'TZS',
        //     externalId: $order->number,
        //     provider: $request['provider'],
        //     additionalProperties: [
        //         'order_id' => $order->id,
        //         'business_id' => $order->business->id,
        //         'business_name' => $order->business->name,
        //     ]
        // );

        // $gateWay = $this->paymentGatewayManager->gateway('azampay');
        // return $gateWay->mnoCheckout($dto);
        $data['order'] = $order;
        return $this->sendResponse($data, 'Payment Completed', HTTP_OK);
    }
}
