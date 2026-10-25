<?php

namespace App\Console\Commands;

use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\BusinessInvoice;
use App\Services\Business\BusinessBillingService;
use Illuminate\Console\Command;

class RunBusinessBilling extends Command
{
    protected $signature = 'billing:process';

    protected $description = 'Generate scheduled Paperstic invoices, dispatch emails and send due reminders.';

    public function handle(BusinessBillingService $billing): int
    {
        if (! $billing->installed()) {
            $this->warn('Billing migration is pending.');

            return self::SUCCESS;
        }
        BusinessBillingPolicy::where('automatic_invoicing', true)->where('next_invoice_at', '<=', now())->eachById(function ($policy) use ($billing) {
            try {
                $billing->generate($policy);
            } catch (\Throwable $error) {
                report($error);
                $this->error('Invoice generation failed for business '.$policy->business_id);
            }
        });
        BusinessInvoice::whereRaw('total > paid_amount + credit_amount')->eachById(function ($invoice) use ($billing) {
            $billing->dispatchInvoice($invoice, (bool) $invoice->sent_at);
        });

        app(\App\Services\Business\BusinessOrderingRestrictionService::class)->syncAll();

        return self::SUCCESS;
    }
}
