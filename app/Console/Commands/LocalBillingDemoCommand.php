<?php

namespace App\Console\Commands;

use App\Services\Business\LocalBillingDemo;
use Illuminate\Console\Command;

class LocalBillingDemoCommand extends Command
{
    protected $signature = 'billing:demo {action=status : status, seed or run} {--execute : Add the isolated demo records to the current LOCAL database}';

    protected $description = 'Create and inspect clearly labelled local hospitality billing fixtures; never deletes existing data.';

    public function handle(LocalBillingDemo $demo): int
    {
        $demo->guard();
        if ($this->argument('action') === 'seed') {
            if (! $this->option('execute')) {
                $this->info('Preview: 10 new [DEMO] businesses, 1,000 synthetic orders, payment records, fee snapshots and invoice scenarios. Existing records are preserved. Add --execute to seed.');

                return self::SUCCESS;
            }
            $demo->seed();
        } elseif ($this->argument('action') === 'run') {
            $demo->run();
        } elseif ($this->argument('action') !== 'status') {
            $this->error('Use status, seed or run.');

            return self::FAILURE;
        }
        $this->line(json_encode($demo->status(), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
