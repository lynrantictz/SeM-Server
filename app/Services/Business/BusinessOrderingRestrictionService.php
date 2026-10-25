<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\BusinessInvoice;
use App\Models\Business\BusinessOrderingRestriction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessOrderingRestrictionService
{
    public function installed(): bool
    {
        return Schema::hasTable('business_ordering_restrictions');
    }

    public function overdue(Business $business): Collection
    {
        $policy = BusinessBillingPolicy::where('business_id', $business->id)->first();
        if (! $policy?->automatic_invoicing || ! $policy->enforce_overdue) {
            return collect();
        }

        return BusinessInvoice::where('business_id', $business->id)->whereNotNull('sent_at')->where('due_at', '<=', now())->whereRaw('total > paid_amount + credit_amount')->orderBy('due_at')->get();
    }

    public function snapshot(Collection $invoices): array
    {
        return $invoices->map(fn ($invoice) => ['uuid' => $invoice->uuid, 'number' => $invoice->number, 'due_at' => $invoice->due_at?->toIso8601String(), 'currency' => $invoice->currency, 'outstanding' => $invoice->outstanding()])->values()->all();
    }

    public function sync(Business $business, string $restorationReason = 'no_overdue_balance', ?int $actor = null): void
    {
        if (! $this->installed()) {
            return;
        }
        DB::transaction(function () use ($business, $restorationReason, $actor) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $policy = BusinessBillingPolicy::where('business_id', $business->id)->first();
            $overdue = $this->overdue($business);
            $active = BusinessOrderingRestriction::where('active_business_id', $business->id)->lockForUpdate()->first();
            if ($overdue->isNotEmpty()) {
                if (! $active) {
                    // Never claim an exact historical activation date that was not recorded.
                    $known = $policy->restriction_enabled_at !== null;
                    $since = $known ? $overdue->first()->due_at->copy()->max($policy->restriction_enabled_at) : now();
                    BusinessOrderingRestriction::create(['business_id' => $business->id, 'active_business_id' => $business->id,
                        'restricted_at' => $since, 'observed_at' => now(), 'date_basis' => $known ? 'policy_and_deadline' : 'first_observed',
                        'reason' => 'overdue_invoice', 'invoice_snapshot' => $this->snapshot($overdue), 'recorded_by' => $actor]);
                }
            } else {
                if ($active) {
                    $active->update(['active_business_id' => null, 'restored_at' => now(), 'restoration_reason' => ! $policy?->enforce_overdue || ! $policy?->automatic_invoicing ? 'enforcement_disabled' : $restorationReason, 'restored_by' => $actor]);
                }
                if ($policy?->enforce_overdue && $policy->automatic_invoicing && ! $policy->restriction_enabled_at) {
                    $policy->update(['restriction_enabled_at' => now()]);
                }
            }
        });
    }

    public function syncAll(): void
    {
        if (! $this->installed()) {
            return;
        }
        Business::whereIn('id', function ($query) {
            $query->select('business_id')->from('business_billing_policies');
        })->orWhereIn('id', function ($query) {
            $query->select('business_id')->from('business_ordering_restrictions')->whereNotNull('active_business_id');
        })->eachById(fn ($business) => $this->sync($business));
    }
}
