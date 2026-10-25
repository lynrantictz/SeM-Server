<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Models\Business\BusinessCommissionPolicy;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessCommissionService
{
    public function installed(): bool
    {
        return Schema::hasTable('business_commission_policies');
    }

    public function current(Business $business, ?CarbonInterface $at = null): ?BusinessCommissionPolicy
    {
        if (! $this->installed()) {
            return null;
        }
        $at ??= now();

        return BusinessCommissionPolicy::where('business_id', $business->id)->where('state', 'published')->where('starts_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))->orderByDesc('starts_at')->orderByDesc('id')->first();
    }

    public function rate(Business $business): float
    {
        $current = $this->current($business);
        if ($current) {
            return (float) $current->rate;
        }
        if ($this->installed() && BusinessCommissionPolicy::where('business_id', $business->id)->where('state', 'published')->exists()) {
            abort(409, 'No commission agreement covers the current time. Contact Operations.');
        }

        return (float) ($business->paymentSetting?->commission_rate ?? SystemSetting::valueFor('payments.default_commission_rate'));
    }

    public function initialize(Business $business): ?BusinessCommissionPolicy
    {
        if (! $this->installed()) {
            return null;
        }

        return DB::transaction(function () use ($business) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            if (BusinessCommissionPolicy::where('business_id', $business->id)->where('state', 'published')->exists()) {
                return $this->current($business);
            }

            return BusinessCommissionPolicy::create([
                'business_id' => $business->id, 'rate' => $this->rate($business), 'starts_at' => now()->startOfSecond(), 'state' => 'published',
                'source' => $business->paymentSetting?->commission_rate === null ? 'system_default' : 'business_override',
                'approved_at' => now(), 'reason' => 'Initial commission agreement established from saved payment configuration.',
            ]);
        });
    }

    public function save(Business $business, array $data, int $actor, ?BusinessCommissionPolicy $draft = null): BusinessCommissionPolicy
    {
        return DB::transaction(function () use ($business, $data, $actor, $draft) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            if ($draft) {
                $draft = BusinessCommissionPolicy::whereKey($draft->id)->lockForUpdate()->firstOrFail();
                abort_unless($draft->business_id === $business->id && $draft->state === 'draft' && ! $draft->fees()->exists(), 409, 'Only unused drafts can be edited or published.');
            }
            $publish = $data['action'] === 'publish';
            $start = $data['apply_now'] ?? false ? now()->startOfSecond() : Carbon::parse($data['starts_at'])->utc()->startOfSecond();
            abort_if($publish && $start->lt(now()->startOfSecond()), 422, 'Commission changes cannot be backdated.');
            if ($publish) {
                $latest = BusinessCommissionPolicy::where('business_id', $business->id)->where('state', 'published')->orderByDesc('starts_at')->orderByDesc('id')->first();
                abort_if(($data['expected_policy_uuid'] ?? null) !== $latest?->uuid, 409, 'The commission agreement changed. Reload before publishing.');
                $this->initialize($business);
                abort_if(BusinessCommissionPolicy::where('business_id', $business->id)->where('state', 'published')->where('starts_at', '>', $start)->exists(), 409, 'A later replacement is already scheduled. Cancel it before inserting an earlier change.');
                $previous = $this->current($business, $start);
                if ($previous) {
                    $previous->update(['ends_at' => $start]);
                }
            }
            $values = ['business_id' => $business->id, 'rate' => $data['rate'], 'starts_at' => $start, 'reason' => $data['reason'], 'source' => 'business_agreement', 'state' => $publish ? 'published' : 'draft'];
            if ($publish) {
                $values += ['state' => 'published', 'approved_by' => $actor, 'approved_at' => now()];
            }
            if ($draft) {
                $draft->fill($values)->save();

                return $draft->fresh();
            }

            return BusinessCommissionPolicy::create([...$values, 'created_by' => $actor]);
        });
    }

    public function cancel(Business $business, BusinessCommissionPolicy $policy, string $reason): void
    {
        DB::transaction(function () use ($business, $policy, $reason) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $policy = BusinessCommissionPolicy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            abort_unless($policy->business_id === $business->id, 404);
            abort_unless(($policy->state === 'draft' || ($policy->state === 'published' && $policy->starts_at->isFuture())) && ! $policy->fees()->exists(), 409, 'Only unused drafts or future policies can be cancelled.');
            if ($policy->state === 'published') {
                $previous = BusinessCommissionPolicy::where('business_id', $business->id)->where('state', 'published')->where('ends_at', $policy->starts_at)->orderByDesc('id')->first();
                if ($previous) {
                    $previous->update(['ends_at' => $policy->ends_at]);
                }
            }
            $policy->update(['state' => 'cancelled', 'cancellation_reason' => $reason]);
        });
    }
}
