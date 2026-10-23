<?php

namespace App\Console\Commands;

use App\Models\Order\OrderCheckoutVerification;
use Illuminate\Console\Command;

class CleanupExpiredCheckouts extends Command
{
    protected $signature = 'orders:cleanup-expired-checkouts';

    protected $description = 'Delete expired checkout verifications that were never linked to an order';

    public function handle(): int
    {
        $deleted = OrderCheckoutVerification::query()
            ->whereNull('order_id')
            ->where('expires_at', '<', now()->subHour())
            ->delete();

        $this->info("Deleted {$deleted} expired checkout verification records.");

        return self::SUCCESS;
    }
}
