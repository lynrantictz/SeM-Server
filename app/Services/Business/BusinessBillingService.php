<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\BusinessInvoice;
use App\Models\Business\BusinessInvoiceItem;
use App\Models\Business\BusinessInvoiceReceipt;
use App\Models\Business\OrderPlatformFee;
use App\Models\Order\Order;
use App\Notifications\BusinessInvoiceNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BusinessBillingService
{
    public function installed(): bool
    {
        return Schema::hasTable('business_billing_policies');
    }

    public function startTrial(Business $business): void
    {
        if (! $this->installed() || ! $business->is_active) {
            return;
        }
        BusinessBillingPolicy::where('business_id', $business->id)->whereNull('trial_started_at')->update(['trial_started_at' => now()]);
    }

    public function feeStartsAt(?BusinessBillingPolicy $policy): ?\Carbon\CarbonInterface
    {
        return $policy?->fee_starts_at ?: ($policy?->trial_started_at ? $policy->trial_started_at->copy()->addDays($policy->trial_days) : null);
    }

    public function syncFee(Order $order): ?OrderPlatformFee
    {
        if (! $this->installed()) {
            return null;
        }

        return DB::transaction(function () use ($order) {
            DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();
            $recentlyCreated = $order->wasRecentlyCreated;
            $order = $order->fresh();
            $order->wasRecentlyCreated = $recentlyCreated;
            $fee = OrderPlatformFee::where('order_id', $order->id)->lockForUpdate()->first();
            $business = $order->business;
            if (! $business) {
                return null;
            }
            if (! $fee) {
                $policy = BusinessBillingPolicy::where('business_id', $business->id)->first();
                $commissions = app(BusinessCommissionService::class);
                $agreement = $order->wasRecentlyCreated ? $commissions->initialize($business) : null;
                if ($order->wasRecentlyCreated && $commissions->installed()) {
                    $agreement = $commissions->current($business, $order->created_at);
                    $firstStart = \App\Models\Business\BusinessCommissionPolicy::where('business_id', $business->id)->where('state', 'published')->min('starts_at');
                    if (! $agreement && $firstStart && $order->created_at->lt($firstStart)) {
                        $order->wasRecentlyCreated = false;
                    } else {
                        abort_unless($agreement, 409, 'No commission agreement covers this order.');
                    }
                }
                $rate = $agreement ? (float) $agreement->rate : $commissions->rate($business);
                $starts = $this->feeStartsAt($policy);
                $free = ($starts && $order->created_at->lt($starts)) || ($policy && $policy->trial_days > 0 && ! $policy->trial_started_at && ! $policy->fee_starts_at);
                // Never retroactively bill pre-existing orders lacking a fee snapshot.
                $legacy = ! $order->wasRecentlyCreated;
                $reason = $legacy ? 'legacy_not_billed' : ($free ? 'free_period' : ($rate == 0 ? 'no_commission' : 'standard'));
                $fee = OrderPlatformFee::create([
                    ...($commissions->installed() ? ['business_commission_policy_id' => $agreement?->id] : []),
                    'order_id' => $order->id, 'business_id' => $business->id, 'rate' => $free || $legacy ? 0 : $rate,
                    'currency' => $business->paymentSetting?->currency ?: $business->district?->city?->country?->currency ?: 'TZS',
                    'reason' => $reason, 'status' => 'estimated',
                ]);
            }
            if (in_array($fee->status, ['invoiced', 'credited'], true)) {
                if ($fee->status === 'invoiced' && in_array($order->status?->name, ['Cancelled', 'Refunded'], true)) {
                    $item = BusinessInvoiceItem::where('order_platform_fee_id', $fee->id)->first();
                    if ($item) {
                        $invoice = BusinessInvoice::whereKey($item->business_invoice_id)->lockForUpdate()->firstOrFail();
                        $available = max(0, (float) $invoice->total - (float) $invoice->receipts()->where('kind', 'credit')->sum('amount'));
                        if ($available > 0 && (float) $fee->amount > 0) {
                            $this->recordReceipt($invoice->id, 'credit', min((float) $fee->amount, $available), 'order-reversal-'.$order->uuid, 'Fee credited after order cancellation or refund.', auth()->id() ?: 0);
                        }
                    }
                    $fee->update(['status' => 'credited']);
                }

                return $fee;
            }
            $baseMinor = max(0, (int) round((float) $order->total_items_amount * 100));
            $rateBasisPoints = (int) round((float) $fee->rate * 100);
            $amount = intdiv($baseMinor * $rateBasisPoints + 5000, 10000) / 100;
            $cancelled = in_array($order->status?->name, ['Cancelled', 'Refunded'], true);
            $billable = $order->status?->name === 'Completed' && $order->paymentStatus?->name === 'Completed';
            $status = $cancelled ? 'reversed' : ($amount == 0 ? 'waived' : ($billable ? 'accrued' : 'estimated'));
            $fee->update(['base_amount' => $baseMinor / 100, 'amount' => $cancelled ? 0 : $amount, 'status' => $status, 'billable_at' => $billable ? ($fee->billable_at ?: now()) : null]);

            return $fee;
        });
    }

    public function orderFee(Order $order): ?array
    {
        if (! $this->installed()) {
            return null;
        }
        $fee = OrderPlatformFee::where('order_id', $order->id)->first();
        if (! $fee) {
            return null;
        }

        return ['commission_policy_uuid' => $fee->commissionPolicy?->uuid, 'legacy_policy' => ! $fee->business_commission_policy_id, 'rate' => (float) $fee->rate, 'base_amount' => (float) $fee->base_amount, 'amount' => (float) $fee->amount, 'currency' => $fee->currency, 'reason' => $fee->reason, 'status' => $fee->status];
    }

    public function restricted(Business $business): bool
    {
        if (! $this->installed()) {
            return false;
        }
        $policy = BusinessBillingPolicy::where('business_id', $business->id)->first();
        if (! $policy?->enforce_overdue || ! $policy->automatic_invoicing) {
            return false;
        }

        return BusinessInvoice::where('business_id', $business->id)->whereNotNull('sent_at')->where('due_at', '<=', now())
            ->whereRaw('total > paid_amount + credit_amount')->exists();
    }

    public function assertCanOrder(Business $business): void
    {
        app(BusinessOrderingRestrictionService::class)->sync($business);
        abort_if($this->restricted($business), 423, 'New orders are temporarily unavailable for this business. Business management can review billing to restore ordering.');
    }

    public function generate(BusinessBillingPolicy $policy): array
    {
        return DB::transaction(function () use ($policy) {
            $policy = BusinessBillingPolicy::lockForUpdate()->findOrFail($policy->id);
            if (! $policy->automatic_invoicing || ! $policy->next_invoice_at || $policy->next_invoice_at->isFuture() || ! $policy->billing_email) {
                return [];
            }
            $end = $policy->next_invoice_at->copy();
            $fees = OrderPlatformFee::where('business_id', $policy->business_id)->where(function ($query) use ($policy) {
                $query->where('status', 'accrued')->orWhere(function ($zero) use ($policy) {
                    $zero->where('status', 'waived')->whereNotNull('billable_at');
                    if ($policy->last_invoice_at) {
                        $zero->where('billable_at', '>=', $policy->last_invoice_at);
                    }
                });
            })->with('order')->where('billable_at', '<', $end)->lockForUpdate()->get();
            $previousStart = function ($cutoff) use ($policy) {
                $local = $cutoff->copy()->setTimezone($policy->business->timezoneDefinition?->identifier ?: $policy->business->timezone ?: 'UTC');
                if ($policy->frequency === 'monthly') {
                    $local->subMonthNoOverflow();
                    $local->day(min($policy->billing_day, $local->daysInMonth));
                } elseif ($policy->frequency === 'custom') {
                    $local->subDays($policy->interval_days);
                } else {
                    $local->subWeek();
                }

                return $local->utc();
            };
            $from = $policy->last_invoice_at ?: $previousStart($end);
            $windows = [[$from, $end]];
            // Initial accrued debt keeps separate billing periods, not one enlarged invoice.
            $earliest = $fees->where('amount', '>', 0)->min('billable_at');
            while (! $policy->last_invoice_at && $earliest && $earliest->lt($from)) {
                $previous = $previousStart($from);
                array_unshift($windows, [$previous, $from]);
                $from = $previous;
            }
            $invoices = [];
            foreach ($windows as [$from, $windowEnd]) {
                $groups = $fees->filter(fn ($fee) => $fee->billable_at->gte($from) && $fee->billable_at->lt($windowEnd))->groupBy('currency');
                foreach ($groups as $currency => $group) {
                    if ($group->sum(fn ($fee) => (int) round((float) $fee->amount * 100)) <= 0) {
                        continue;
                    }
                    $summary = [];
                    if (Schema::hasColumn('business_invoices', 'order_count')) {
                        $business = $policy->business;
                        $summary = [
                            'order_count' => $group->count(),
                            'gross_sales' => $group->sum(fn ($fee) => (int) round((float) $fee->order->total_amount * 100)) / 100,
                            'order_tax_total' => $group->sum(fn ($fee) => (int) round((float) $fee->order->tax_amount * 100)) / 100,
                            'commission_base_total' => $group->sum(fn ($fee) => (int) round((float) $fee->base_amount * 100)) / 100,
                            'rate_breakdown' => $group->groupBy(fn ($fee) => $fee->rate.'|'.$fee->reason)->map(fn ($rows) => [
                                'rate' => (float) $rows->first()->rate, 'reason' => $rows->first()->reason, 'order_count' => $rows->count(),
                                'base_amount' => $rows->sum(fn ($fee) => (int) round((float) $fee->base_amount * 100)) / 100,
                                'fee_amount' => $rows->sum(fn ($fee) => (int) round((float) $fee->amount * 100)) / 100,
                            ])->values()->all(),
                            'issuer_snapshot' => config('business_billing.issuer', ['name' => 'Paperstic', 'email' => config('mail.from.address')]),
                            'recipient_snapshot' => ['business' => $business->name, 'vendor' => $business->vendor?->name, 'address' => $business->location,
                                'country' => $business->district?->city?->country?->name, 'tax_id' => $business->tin, 'email' => $policy->billing_email],
                            'business_timezone' => $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC',
                        ];
                    }
                    $invoice = BusinessInvoice::create([
                        ...$summary,
                        'business_id' => $policy->business_id, 'number' => 'PST-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                        'currency' => $currency, 'total' => $group->sum(fn ($fee) => (int) round((float) $fee->amount * 100)) / 100,
                        'billing_email' => $policy->billing_email, 'payment_instructions' => $policy->payment_instructions,
                        'period_from' => $from, 'period_to' => $windowEnd, 'status' => 'draft',
                    ]);
                    foreach ($group as $fee) {
                        BusinessInvoiceItem::create(['business_invoice_id' => $invoice->id, 'order_platform_fee_id' => $fee->id, 'order_number' => $fee->order?->number ?: $fee->order_id, 'base_amount' => $fee->base_amount, 'rate' => $fee->rate, 'amount' => $fee->amount]);
                        $fee->update(['status' => 'invoiced']);
                    }
                    $this->applyCreditBalance($invoice);
                    $invoices[] = $invoice->fresh();
                }
            }
            $business = $policy->business;
            $zone = $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC';
            $next = CarbonImmutable::instance($end)->setTimezone($zone);
            $next = match ($policy->frequency) {
                'monthly' => $next->addMonthNoOverflow()->day(min($policy->billing_day, $next->addMonthNoOverflow()->daysInMonth)),
                'custom' => $next->addDays($policy->interval_days),
                default => $next->addWeek(),
            };
            $policy->update(['last_invoice_at' => $end, 'next_invoice_at' => $next->utc()]);

            return $invoices;
        });
    }

    private function applyCreditBalance(BusinessInvoice $invoice): void
    {
        $prior = BusinessInvoice::where('business_id', $invoice->business_id)->where('currency', $invoice->currency)->where('id', '<', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($prior as $old) {
            $available = max(0, round((float) $old->paid_amount + (float) $old->credit_amount - (float) $old->total, 2));
            $use = min($available, $invoice->outstanding());
            if ($use <= 0) {
                continue;
            }
            BusinessInvoiceReceipt::create(['business_invoice_id' => $old->id, 'recorded_by' => 0, 'kind' => 'credit_transfer_out', 'amount' => $use, 'reference' => $invoice->uuid, 'notes' => 'Credit carried to '.$invoice->number]);
            BusinessInvoiceReceipt::create(['business_invoice_id' => $invoice->id, 'recorded_by' => 0, 'kind' => 'credit_transfer_in', 'amount' => $use, 'reference' => $old->uuid, 'notes' => 'Credit carried from '.$old->number]);
            $old->update(['credit_amount' => (float) $old->credit_amount - $use]);
            $invoice->credit_amount = (float) $invoice->credit_amount + $use;
        }
        if ($invoice->outstanding() <= 0) {
            $invoice->status = 'credited';
        }
        $invoice->save();
    }

    public function recordReceipt(int $invoiceId, string $kind, float $amount, string $reference, ?string $notes, int $actor): BusinessInvoice
    {
        return DB::transaction(function () use ($invoiceId, $kind, $amount, $reference, $notes, $actor) {
            $owner = BusinessInvoice::findOrFail($invoiceId)->business;
            Business::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            app(BusinessOrderingRestrictionService::class)->sync($owner);
            $invoice = BusinessInvoice::lockForUpdate()->findOrFail($invoiceId);
            $existing = $invoice->receipts()->where('kind', $kind)->where('reference', $reference)->first();
            if ($existing) {
                abort_unless(abs((float) $existing->amount - $amount) < 0.005, 422, 'This reference was already recorded for another amount.');

                return $invoice;
            }
            $max = $kind === 'credit' ? (float) $invoice->total - (float) $invoice->receipts()->where('kind', 'credit')->sum('amount') : $invoice->outstanding();
            abort_unless($amount > 0 && round($amount, 2) <= round($max, 2), 422, 'The amount exceeds the available invoice balance.');
            $invoice->receipts()->create(['kind' => $kind, 'amount' => round($amount, 2), 'reference' => $reference, 'notes' => $notes, 'recorded_by' => $actor]);
            $field = $kind === 'credit' ? 'credit_amount' : 'paid_amount';
            $invoice->{$field} = round((float) $invoice->{$field} + $amount, 2);
            if ($invoice->outstanding() <= 0) {
                $invoice->status = $kind === 'credit' && (float) $invoice->paid_amount == 0 ? 'credited' : 'paid';
                $invoice->paid_at = $invoice->paid_at ?: now();
            }
            $invoice->save();
            app(BusinessOrderingRestrictionService::class)->sync($owner, $kind === 'payment' ? 'payment_confirmed' : 'credit_applied', $actor ?: null);

            return $invoice;
        });
    }

    public function dispatchInvoice(BusinessInvoice $invoice, bool $reminder = false): bool
    {
        return DB::transaction(function () use ($invoice, $reminder) {
            $invoice = BusinessInvoice::lockForUpdate()->findOrFail($invoice->id);
            $policy = BusinessBillingPolicy::where('business_id', $invoice->business_id)->first();
            if (! $policy?->automatic_invoicing || $invoice->outstanding() <= 0 || (! $reminder && $invoice->sent_at)) {
                return false;
            }
            if ($reminder && (! $invoice->sent_at || ! $invoice->due_at || $invoice->due_at->gt(now()->addDay()) || ($invoice->reminded_at && $invoice->reminded_at->gt(now()->subDay())))) {
                return false;
            }
            try {
                if (! $invoice->sent_at && $policy->billing_email) {
                    $invoice->billing_email = $policy->billing_email;
                }
                // Synchronous dispatch: a queued job is not proof that an email was sent.
                if (app()->environment('production') && in_array(config('mail.default'), ['log', 'array'], true)) {
                    throw new \RuntimeException('Configure a delivering email transport before dispatching business invoices.');
                }
                $deadline = $invoice->due_at ?: now()->addHours($policy->grace_hours);
                $emailInvoice = clone $invoice;
                $emailInvoice->due_at = $deadline;
                Notification::route('mail', $invoice->billing_email)->notify(new BusinessInvoiceNotification($emailInvoice->load(['business', 'items']), $reminder));
                $changes = ['delivery_error' => null];
                if ($reminder) {
                    $changes['reminded_at'] = now();
                } else {
                    $changes += ['status' => 'sent', 'sent_at' => now(), 'due_at' => $deadline];
                }
                $invoice->update($changes);

                return true;
            } catch (\Throwable $error) {
                $invoice->update(['delivery_error' => mb_substr($error->getMessage(), 0, 2000)]);
                report($error);

                return false;
            }
        });
    }
}
