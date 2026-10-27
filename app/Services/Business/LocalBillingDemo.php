<?php

namespace App\Services\Business;

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\BusinessCommissionPolicy;
use App\Models\Business\BusinessInvoice;
use App\Models\Business\BusinessPaymentMethod;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\Vendor;
use App\Models\Customer\Customer;
use App\Models\Location\District;
use App\Models\Menu\Category;
use App\Models\Menu\Item;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\PaymentMethod;
use App\Services\PaymentGateway\PaymentSettlementService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalBillingDemo
{
    public const EMAIL = 'billing-demo-owner@paperstic.test';

    public const PASSWORD = 'PapersticDemo@2026!';

    public const SCENARIOS = ['Paid weekly invoice', 'Overdue — ordering blocked', 'Partially paid overdue — ordering blocked', 'Invoice within grace period', 'Monthly billing — next month', 'Seven-day free period', 'Fourteen-day trial ended', 'Zero commission', 'Rate changed — invoice generation due now', 'Deadline extended — ordering restored'];

    public function guard(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'Billing demo tools are available only locally.');
    }

    public function businesses()
    {
        return Business::whereHas('vendor', fn ($q) => $q->where('email', 'billing-demo-vendor@paperstic.test'))->where('code_prefix', 'like', 'BDM%');
    }

    private function at($time, callable $callback): mixed
    {
        $previous = Carbon::getTestNow();
        $immutable = CarbonImmutable::getTestNow();
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previous);
            CarbonImmutable::setTestNow($immutable);
        }
    }

    public function seed(): array
    {
        $this->guard();
        abort_if(Vendor::where('email', 'billing-demo-vendor@paperstic.test')->exists(), 409, 'This demo dataset already exists. Existing records have been left unchanged.');
        $fixtures = json_decode(file_get_contents(database_path('fixtures/billing-demo.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            foreach ($fixture['assets'] as $asset) {
                if (! app()->environment('testing')) {
                    abort_unless(Storage::disk('public')->exists($asset['path']), 422, 'Download all demo assets first.');
                }
            }
        }
        $anchor = Carbon::now('Africa/Dar_es_Salaam')->startOfMinute()->utc();
        $mail = config('mail.default');
        config(['mail.default' => 'log']);
        try {
            return DB::transaction(function () use ($fixtures, $anchor) {
                $owner = User::withoutEvents(fn () => User::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'Billing Demo Owner', 'email' => self::EMAIL, 'password' => self::PASSWORD, 'type' => 'owner', 'is_active' => true, 'email_verified_at' => $anchor]));
                $owner->assignRole(\Spatie\Permission\Models\Role::findOrCreate('owner', 'api'));
                $operator = User::create(['name' => 'Billing Demo Operations', 'email' => 'billing-demo-ops@paperstic.test', 'password' => self::PASSWORD, 'type' => 'paperstic', 'is_active' => true, 'email_verified_at' => $anchor]);
                $operationsRole = \Spatie\Permission\Models\Role::findOrCreate('billing-demo-operations', 'api');
                $operationsRole->syncPermissions(\Spatie\Permission\Models\Permission::where('name', 'like', 'operations.%')->get());
                $operator->assignRole($operationsRole);
                $country = \App\Models\Location\Country::where('iso2', 'TZ')->firstOrFail();
                $vendor = Vendor::withoutEvents(fn () => Vendor::forceCreate(['uuid' => (string) Str::uuid(), 'country_id' => $country->id, 'name' => '[DEMO] Dar Hospitality Billing Lab', 'email' => 'billing-demo-vendor@paperstic.test', 'address' => 'LOCAL DEMO ONLY — Dar es Salaam']));
                $owner->vendors()->attach($vendor->id, ['is_primary' => true, 'is_active' => true, 'role' => 'owner', 'access_scope' => 'all_businesses']);
                $pdf = new \Dompdf\Dompdf;
                $pdf->loadHtml('<h1>LOCAL DEMO ONLY</h1><p>Synthetic compliance placeholder for testing. This is not a legal document or evidence of a real establishment’s approval.</p>');
                $pdf->render();
                Storage::disk('public')->put('billing-demo/demo-compliance.pdf', $pdf->output());
                $billing = app(BusinessBillingService::class);
                $manifest = ['anchor' => $anchor->toIso8601String(), 'timezone' => 'Africa/Dar_es_Salaam', 'businesses' => []];
                foreach ($fixtures as $index => $fixture) {
                    $n = $index + 1;
                    $district = District::where('name', $fixture['district'])->whereHas('city', fn ($q) => $q->where('name', 'Dar es Salaam')->where('country_id', $country->id))->firstOrFail();
                    $business = Business::withoutEvents(fn () => Business::forceCreate(['uuid' => (string) Str::uuid(), 'vendor_id' => $vendor->id, 'district_id' => $district->id, 'business_type_id' => \App\Models\Business\BusinessType::where('name', $fixture['type'])->value('id'), 'name' => '[DEMO] '.$fixture['name'], 'tin' => 'DEMO-ONLY-'.str_pad($n, 3, '0', STR_PAD_LEFT), 'location' => $fixture['address'].', Dar es Salaam', 'latitude' => $fixture['latitude'], 'longitude' => $fixture['longitude'], 'location_verified_at' => $anchor, 'logo_disk' => 'public', 'logo_path' => $fixture['assets']['logo']['path'], 'logo_mime_type' => $fixture['assets']['logo']['mime'], 'logo_updated_at' => $anchor, 'image_url' => Storage::disk('public')->url($fixture['assets']['discovery']['path']), 'discovery_description' => 'LOCAL DEMO ONLY. '.($fixture['hotel_stars'] ? $fixture['hotel_stars'].'-star hotel reference. ' : '').'Synthetic menu, accounts and transactions; not a real Paperstic partner listing.', 'is_active' => true, 'tax_allowed' => false, 'tax_status' => 'not_registered', 'timezone' => 'Africa/Dar_es_Salaam', 'order_prefix' => 'ORD-BDM'.str_pad($n, 2, '0', STR_PAD_LEFT), 'code_prefix' => 'BDM'.str_pad($n, 2, '0', STR_PAD_LEFT), 'current_order_number' => 100, 'dine_in_enabled' => true, 'online_ordering_enabled' => true, 'pickup_enabled' => true, 'delivery_enabled' => false]));
                    $business->paymentSetting()->create(['provider' => 'manual', 'currency' => 'TZS', 'commission_rate' => $n === 8 ? 0 : 1, 'commission_basis' => 'subtotal_excluding_tax', 'fee_bearer' => 'business', 'settlement_mode' => 'manual_hold']);
                    $oldest = $anchor->copy()->subDays($n === 6 ? 6 : 26);
                    $start = $oldest->copy()->subDay();
                    $policy = BusinessBillingPolicy::create(['business_id' => $business->id, 'automatic_invoicing' => true, 'enforce_overdue' => true, 'restriction_enabled_at' => $start, 'billing_email' => 'billing-demo-'.$n.'@paperstic.test', 'frequency' => $n === 5 ? 'monthly' : 'weekly', 'interval_days' => 7, 'billing_day' => 1, 'trial_days' => $n === 6 ? 7 : ($n === 7 ? 14 : 0), 'trial_started_at' => $n === 6 ? $oldest : ($n === 7 ? $anchor->copy()->subDays(26) : $start), 'fee_starts_at' => in_array($n, [6, 7]) ? null : $start, 'next_invoice_at' => $n === 5 ? $anchor->copy()->setTimezone('Africa/Dar_es_Salaam')->addMonthNoOverflow()->startOfMonth()->setHour(9)->utc() : $anchor->copy()->addMinutes(10), 'grace_hours' => 72, 'payment_instructions' => 'LOCAL DEMO — do not send money. Record a synthetic Paperstic invoice payment in Operations.']);
                    BusinessCommissionPolicy::create(['business_id' => $business->id, 'rate' => $n === 8 ? 0 : 1, 'state' => 'published', 'source' => 'business_agreement', 'starts_at' => $start, 'ends_at' => $n === 9 ? $anchor->copy()->subDays(12) : null, 'approved_at' => $start, 'reason' => 'Local billing demo agreement', 'created_by' => $operator->id, 'approved_by' => $operator->id]);
                    if ($n === 9) {
                        BusinessCommissionPolicy::create(['business_id' => $business->id, 'rate' => 2, 'state' => 'published', 'source' => 'business_agreement', 'starts_at' => $anchor->copy()->subDays(12), 'approved_at' => $anchor->copy()->subDays(12), 'reason' => 'Local demo rate replacement; earlier orders retain 1%', 'created_by' => $operator->id, 'approved_by' => $operator->id]);
                    }
                    $staff = [];
                    foreach (['manager' => 'business_manager', 'waiter' => 'waiter', 'counter' => 'counter'] as $key => $role) {
                        $user = User::create(['name' => 'Demo '.$key.' '.$n, 'email' => 'billing-demo-'.$key.'-'.$n.'@paperstic.test', 'password' => self::PASSWORD, 'type' => 'business', 'is_active' => true, 'email_verified_at' => $anchor]);
                        $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate(match ($role) {
                            'business_manager' => 'manager', 'counter' => 'counter-clerk', default => $role
                        }, 'api'));
                        $user->businesses()->attach($business->id, ['business_role' => $role, 'title' => 'DEMO '.$key.' '.$n, 'is_active' => true]);
                        $staff[$key] = $user;
                    }
                    $methods = [];
                    foreach (['mpesa_lipa_namba', 'mixx_merchant', 'cash', 'pos_card', 'bank_transfer'] as $code) {
                        $method = PaymentMethod::where('code', $code)->firstOrFail();
                        $configuration = BusinessPaymentMethod::create(['business_id' => $business->id, 'payment_method_id' => $method->id, 'identifier' => 'DEMO-'.str_pad($n, 3, '0', STR_PAD_LEFT).'-'.$method->id, 'account_holder_name' => '[DEMO] '.$fixture['name'], 'status' => 'active', 'is_enabled' => true, 'verified_by_user_id' => $operator->id, 'verified_at' => $anchor]);
                        $account = $code === 'cash' ? null : $configuration->accounts()->create(['label' => 'LOCAL DEMO ONLY', 'bank_name' => $code === 'bank_transfer' ? 'DEMO BANK — no transfers' : $method->name, 'account_number' => 'DEMO-'.$n.'-'.$method->id, 'account_holder_name' => '[DEMO] '.$fixture['name'], 'currency' => 'TZS', 'status' => 'active', 'is_enabled' => true, 'is_default' => true]);
                        $methods[] = [$method, $account];
                    }
                    $section = \App\Models\Section\Section::create(['business_id' => $business->id, 'user_id' => $owner->id, 'name' => 'Dining', 'description' => 'DEMO Dining '.$n, 'is_active' => true]);
                    $point = \App\Models\Section\ServicePoint::create(['business_id' => $business->id, 'section_id' => $section->id, 'type' => 'table', 'label' => '1', 'display_name' => 'Demo table 1', 'capacity' => 4, 'is_active' => true]);
                    $channels = \App\Models\Business\OrderingChannel::whereIn('slug', ['dine_in', 'online', 'pickup'])->pluck('id');
                    foreach ($channels as $channel) {
                        $business->orderingChannels()->attach($channel, ['is_enabled' => true]);
                    }
                    $point->orderingChannels()->attach(\App\Models\Business\OrderingChannel::where('slug', 'dine_in')->value('id'));
                    $point->codes()->create(['code' => app(\App\Services\CodeGenerator::class)->generate(8), 'is_active' => true]);
                    $onlinePoint = app(\App\Services\OnlineOrderingAreaService::class)->sync($business, true, $owner->id);
                    $category = Category::create(['business_id' => $business->id, 'name' => 'Demo menu', 'is_active' => true]);
                    $items = collect([['Coffee', 5000], ['Breakfast', 15000], ['Grilled fish', 25000]])->map(fn ($item) => Item::create(['category_id' => $category->id, 'name' => $item[0], 'description' => 'Synthetic menu item for local testing', 'price' => $item[1], 'currency' => 'TZS', 'discount' => 0, 'final_price' => $item[1], 'is_active' => true]));
                    foreach (range(0, 6) as $day) {
                        DB::table('business_opening_hours')->insert(['business_id' => $business->id, 'day_of_week' => $day, 'opens_at' => '00:00:00', 'closes_at' => '23:59:59', 'is_closed' => false, 'sort_order' => 0, 'created_at' => $anchor, 'updated_at' => $anchor]);
                    }
                    foreach (range(1, 100) as $j) {
                        $created = $oldest->copy()->addSeconds((int) (($anchor->timestamp - $oldest->timestamp - 3600) * ($j - 1) / 99));
                        $this->at($created, function () use ($business, $methods, $items, $point, $onlinePoint, $staff, $j, $n, $anchor) {
                            [$method, $account] = $methods[($j - 1) % 5];
                            $item = $items[($j - 1) % 3];
                            $quantity = 1 + $j % 4;
                            $amount = (float) $item->price * $quantity;
                            $remainder = $j % 20;
                            $status = $remainder < 15 ? 'Completed' : ($remainder === 15 ? 'Ready' : ($remainder === 16 ? 'Processing' : ($remainder === 17 ? 'Cancelled' : 'Scheduled')));
                            $paid = in_array($status, ['Completed', 'Ready']);
                            $customer = Customer::create(['phone' => 'DEMO-BDM'.$n.'-'.$j]);
                            $order = Order::create(['business_id' => $business->id, 'customer_id' => $customer->id, 'user_id' => $j % 2 ? $staff['waiter']->id : null, 'service_point_id' => $j % 3 ? $point->id : $onlinePoint->id, 'code_id' => $j % 3 ? $point->activeCode?->id : $onlinePoint->activeCode?->id, 'channel' => $j % 3 ? 'dine_in' : 'online', 'number' => 'ORD-BDM'.str_pad($n, 2, '0', STR_PAD_LEFT).'-'.str_pad($j, 6, '0', STR_PAD_LEFT), 'order_status_id' => DB::table('order_statuses')->where('name', $status)->value('id'), 'payment_status_id' => DB::table('payment_statuses')->where('name', 'Pending')->value('id'), 'payment_method_id' => $method->id, 'total_items_amount' => $amount, 'total_amount' => $amount, 'tax_amount' => 0, 'due_amount' => $amount, 'paid_amount' => 0, 'phone_verified_at' => now(), 'scheduled_for' => $status === 'Scheduled' ? $anchor->copy()->addDay() : null, 'comment' => 'LOCAL BILLING DEMO — synthetic transaction']);
                            $order->items()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => $item->price, 'discount' => 0, 'final_price' => $item->price, 'total_amount' => $amount]);
                            if ($paid) {
                                app(PaymentSettlementService::class)->recordManualPayment($order, $method->id, $amount, 'DEMO-BDM'.$n.'-'.$j, $staff['counter']->id, 'staff_manual', $account?->uuid);
                            } else {
                                Payment::create(['order_id' => $order->id, 'external_id' => 'DEMO-PENDING-BDM'.$n.'-'.$j, 'account_number' => $method->code, 'provider' => $method->code, 'amount' => $amount, 'currency' => 'TZS', 'status' => $status === 'Cancelled' ? 'FAILED' : 'PENDING', 'initiated_by_user_id' => $staff['waiter']->id, 'metadata' => ['mode' => 'manual', 'payment_account_uuid' => $account?->uuid]]);
                            }
                            DB::table('order_status_histories')->insert(['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'to_status_id' => $order->order_status_id, 'created_at' => now(), 'updated_at' => now()]);
                        });
                    }
                    // Seed approved, explicitly synthetic evidence only on new demo owners.
                    foreach ([['vendor', $vendor], ['business', $business]] as [$scope, $entity]) {
                        foreach (app(ComplianceDocumentRequirements::class)->available($scope, $entity) as $definition) {
                            if ($scope === 'vendor' && ComplianceDocument::where('vendor_id', $vendor->id)->where('document_type', $definition['key'])->exists()) {
                                continue;
                            }
                            ComplianceDocument::withoutEvents(fn () => ComplianceDocument::forceCreate(['uuid' => (string) Str::uuid(), 'vendor_id' => $scope === 'vendor' ? $vendor->id : null, 'business_id' => $scope === 'business' ? $business->id : null, 'compliance_document_type_id' => $definition['id'], 'document_type' => $definition['key'], 'document_type_name' => $definition['name'], 'document_number' => 'DEMO-ONLY-'.$n.'-'.$definition['id'], 'original_filename' => 'LOCAL-DEMO-NOT-A-LEGAL-DOCUMENT.pdf', 'disk' => 'public', 'storage_path' => 'billing-demo/demo-compliance.pdf', 'mime_type' => 'application/pdf', 'file_size' => Storage::disk('public')->size('billing-demo/demo-compliance.pdf'), 'status' => 'approved', 'uploaded_by' => $owner->id, 'reviewed_by' => $operator->id, 'reviewed_at' => $anchor]));
                        }
                    }
                    if (in_array($n, [1, 2, 3, 4, 7, 10])) {
                        $sent = $anchor->copy()->subDays($n === 4 ? 1 : 3)->subHours(4);
                        $policy->update(['next_invoice_at' => $sent]);
                        $invoices = $this->at($sent, fn () => $billing->generate($policy));
                        $this->at($sent, function () use ($billing, $invoices, $n, $operator) {
                            foreach ($invoices as $i => $invoice) {
                                $billing->dispatchInvoice($invoice);
                                $last = $i === count($invoices) - 1;
                                if (in_array($n, [1, 7]) || (! $last && $n !== 4)) {
                                    $billing->recordReceipt($invoice->id, 'payment', (float) $invoice->total, 'DEMO-PAID-'.$invoice->number, 'Synthetic local invoice payment', $operator->id);
                                } elseif ($n === 3 && $last) {
                                    $billing->recordReceipt($invoice->id, 'payment', round((float) $invoice->total / 2, 2), 'DEMO-PARTIAL-'.$invoice->number, 'Synthetic partial payment', $operator->id);
                                }
                            }
                        });
                        if ($n === 10 && $invoices) {
                            $this->at($anchor->copy()->subHours(2), fn () => app(BusinessOrderingRestrictionService::class)->sync($business));
                            $last = end($invoices);
                            $last->update(['due_at' => $anchor->copy()->addDays(3)]);
                            app(BusinessOrderingRestrictionService::class)->sync($business, 'deadline_extended', $operator->id);
                        }
                        $policy->refresh()->update(['next_invoice_at' => $anchor->copy()->addDays(7)]);
                    }
                    if ($n === 9) {
                        $policy->update(['next_invoice_at' => $anchor->copy()->subMinute()]);
                    }
                    app(BusinessOrderingRestrictionService::class)->sync($business);
                    $manifest['businesses'][] = ['uuid' => $business->uuid, 'name' => $business->name, 'scenario' => self::SCENARIOS[$index], 'hotel_stars' => $fixture['hotel_stars'], 'source' => $fixture['source'], 'latitude' => $fixture['latitude'], 'longitude' => $fixture['longitude']];
                }
                Storage::disk('local')->put('billing-demo/'.$vendor->uuid.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

                return $manifest;
            });
        } finally {
            config(['mail.default' => $mail]);
        }
    }

    public function status(): array
    {
        $this->guard();
        $vendor = Vendor::where('email', 'billing-demo-vendor@paperstic.test')->first();
        if (! $vendor) {
            return ['enabled' => true, 'seeded' => false, 'businesses' => [], 'runs' => []];
        }
        $manifest = json_decode(Storage::disk('local')->get('billing-demo/'.$vendor->uuid.'/manifest.json') ?: '{}', true);
        $rows = $this->businesses()->orderBy('id')->get();
        $policies = BusinessBillingPolicy::whereIn('business_id', $rows->pluck('id'))->get()->keyBy('business_id');

        return ['enabled' => true, 'seeded' => true, 'now' => now()->toIso8601String(), 'anchor' => $manifest['anchor'] ?? null, 'timezone' => 'Africa/Dar_es_Salaam', 'businesses' => $rows->map(fn ($business, $index) => ['uuid' => $business->uuid, 'name' => $business->name, 'scenario' => self::SCENARIOS[$index] ?? 'Demo', 'orders' => Order::where('business_id', $business->id)->count(), 'restricted' => app(BusinessBillingService::class)->restricted($business), 'next_invoice_at' => $policies->get($business->id)?->next_invoice_at?->toIso8601String(), 'fee_starts_at' => app(BusinessBillingService::class)->feeStartsAt($policies->get($business->id))?->toIso8601String(), 'frequency' => $policies->get($business->id)?->frequency, 'invoices' => BusinessInvoice::where('business_id', $business->id)->count()]), 'runs' => json_decode(Storage::disk('local')->get('billing-demo/'.$vendor->uuid.'/runs.json') ?: '[]', true)];
    }

    public function run(): array
    {
        $this->guard();
        $vendor = Vendor::where('email', 'billing-demo-vendor@paperstic.test')->firstOrFail();
        $start = now()->toIso8601String();
        $generated = 0;
        $errors = [];
        $mail = config('mail.default');
        config(['mail.default' => 'log']);
        try {
            foreach ($this->businesses()->get() as $business) {
                try {
                    $policy = BusinessBillingPolicy::where('business_id', $business->id)->first();
                    if ($policy) {
                        $generated += count(app(BusinessBillingService::class)->generate($policy));
                    }
                    BusinessInvoice::where('business_id', $business->id)->whereRaw('total > paid_amount + credit_amount')->each(fn ($invoice) => app(BusinessBillingService::class)->dispatchInvoice($invoice, (bool) $invoice->sent_at));
                    app(BusinessOrderingRestrictionService::class)->sync($business);
                } catch (\Throwable $error) {
                    $errors[] = ['business' => $business->name, 'message' => $error->getMessage()];
                }
            }
        } finally {
            config(['mail.default' => $mail]);
        }
        $path = 'billing-demo/'.$vendor->uuid.'/runs.json';
        $runs = json_decode(Storage::disk('local')->get($path) ?: '[]', true);
        array_unshift($runs, ['started_at' => $start, 'finished_at' => now()->toIso8601String(), 'invoices_created' => $generated, 'errors' => $errors]);
        Storage::disk('local')->put($path, json_encode(array_slice($runs, 0, 100), JSON_PRETTY_PRINT));

        return $this->status();
    }
}
