<?php

namespace App\Console\Commands;

use App\Services\Business\BusinessOrderingRestrictionService;
use Illuminate\Console\Command;

class SyncBusinessOrderingRestrictions extends Command
{
    protected $signature = 'billing:sync-restrictions';

    protected $description = 'Record ordering restrictions and restorations without changing business activation.';

    public function handle(BusinessOrderingRestrictionService $restrictions): int
    {
        if (! $restrictions->installed()) {
            $this->warn('Ordering restriction history migration is pending.');

            return self::SUCCESS;
        }
        $restrictions->syncAll();

        return self::SUCCESS;
    }
}
