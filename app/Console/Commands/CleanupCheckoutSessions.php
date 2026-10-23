<?php

namespace App\Console\Commands;

use App\Models\Order\OrderCheckoutVerification;
use App\Models\Order\OrderCustomerSession;
use Illuminate\Console\Command;

class CleanupCheckoutSessions extends Command
{
    protected $signature = 'orders:cleanup-checkout-sessions';

    protected $description = 'Delete old completed checkout verifications and expired customer sessions';

    public function handle(): int
    {
        $verifications = OrderCheckoutVerification::query()
            ->whereNotNull('order_id')
            ->where('created_at', '<', now()->subDays(7))
            ->delete();

        $sessions = OrderCustomerSession::query()
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Deleted {$verifications} completed checkout verifications and {$sessions} expired customer sessions.");

        return self::SUCCESS;
    }
}
