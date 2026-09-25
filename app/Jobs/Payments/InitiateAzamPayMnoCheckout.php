<?php

namespace App\Jobs\Payments;

use App\Services\PaymentGateway\PaymentCheckoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InitiateAzamPayMnoCheckout implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * One gateway attempt is intentional. Retrying after an unknown network
     * timeout could create a second prompt on the guest's phone.
     */
    public int $tries = 1;

    /** Keep below the PHP worker's process timeout. */
    public int $timeout = 30;

    public function __construct(public readonly int $paymentId)
    {
    }

    public function handle(PaymentCheckoutService $checkout): void
    {
        $checkout->sendQueuedMnoCheckout($this->paymentId);
    }
}
