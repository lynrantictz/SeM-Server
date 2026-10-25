<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessInvoice;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Services\Business\BusinessBillingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00 UTC');
    config(['business_billing.issuer' => ['name' => 'Paperstic', 'email' => 'billing@paperstic.example']]);
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->vendor = Vendor::create(['name' => 'Billing vendor', 'email' => 'billing-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Billing restaurant', 'vendor_id' => $this->vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->business->paymentSetting()->create(['provider' => 'manual', 'currency' => 'TZS', 'commission_rate' => 3, 'commission_basis' => 'subtotal_excluding_tax', 'fee_bearer' => 'business', 'settlement_mode' => 'manual_hold']);
    $this->operator = User::create(['name' => 'Billing operator', 'email' => 'billing-ops@example.test', 'type' => 'paperstic', 'password' => 'Example@2026', 'is_active' => true]);
    foreach (['operations.businesses.view', 'operations.settlements.manage'] as $permission) {
        Permission::findOrCreate($permission, 'api');
        $this->operator->givePermissionTo($permission);
    }
    $this->owner = User::create(['name' => 'Owner', 'email' => 'billing-owner@example.test', 'type' => 'owner', 'password' => 'Example@2026', 'is_active' => true]);
    $this->owner->vendors()->attach($this->vendor->id, ['is_primary' => true, 'is_active' => true]);
    $this->url = '/v1/operations/businesses/'.$this->business->uuid.'/billing';
    $this->payload = ['automatic_invoicing' => false, 'enforce_overdue' => false, 'billing_email' => 'billing-owner@example.test', 'frequency' => 'weekly', 'interval_days' => 7, 'trial_days' => 0, 'fee_starts_at' => null, 'next_invoice_at' => null, 'grace_hours' => 72, 'payment_instructions' => 'Pay Paperstic using reference shown on this invoice.'];
    $this->service = app(BusinessBillingService::class);
    $this->actingAs($this->operator, 'api');
});
afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});
function restrictionPolicy($test, bool $known = true): \App\Models\Business\BusinessBillingPolicy
{
    return \App\Models\Business\BusinessBillingPolicy::create(['business_id' => $test->business->id, 'automatic_invoicing' => true, 'enforce_overdue' => true, 'restriction_enabled_at' => $known ? now()->subDays(5) : null, 'billing_email' => 'billing@example.test']);
}
function restrictionInvoice($test, array $attributes = []): BusinessInvoice
{
    return BusinessInvoice::create([...['business_id' => $test->business->id, 'number' => 'LOCK-'.Str::random(10), 'currency' => 'TZS', 'total' => 300, 'billing_email' => 'billing@example.test', 'period_from' => now()->subDays(10), 'period_to' => now()->subDays(3), 'status' => 'sent', 'sent_at' => now()->subDays(3), 'due_at' => now()], ...$attributes]);
}
it('records the exact known restriction boundary once and leaves activation untouched', function () {
    restrictionPolicy($this);
    $invoice = restrictionInvoice($this);
    $service = app(\App\Services\Business\BusinessOrderingRestrictionService::class);
    Carbon::setTestNow(now()->subSecond());
    $service->sync($this->business);
    $this->assertDatabaseCount('business_ordering_restrictions', 0);
    Carbon::setTestNow(now()->addSecond());
    $service->sync($this->business);
    $service->sync($this->business);
    $event = \App\Models\Business\BusinessOrderingRestriction::firstOrFail();
    expect($event->restricted_at->equalTo($invoice->due_at))->toBeTrue();
    expect($event->date_basis)->toBe('policy_and_deadline');
    $this->assertDatabaseCount('business_ordering_restrictions', 1);
    expect((bool) $this->business->fresh()->is_active)->toBeTrue();
    expect($this->service->restricted($this->business))->toBeTrue();
});
it('keeps restriction for partial payment and records restoration once after full payment', function () {
    restrictionPolicy($this);
    $invoice = restrictionInvoice($this);
    $this->service->recordReceipt($invoice->id, 'payment', 100, 'PART-1', null, $this->operator->id);
    $event = \App\Models\Business\BusinessOrderingRestriction::firstOrFail();
    expect($event->restored_at)->toBeNull();
    Carbon::setTestNow(now()->addHour());
    $this->service->recordReceipt($invoice->id, 'payment', 200, 'FINAL', null, $this->operator->id);
    expect($event->fresh()->restoration_reason)->toBe('payment_confirmed');
    expect($event->fresh()->restored_at->equalTo(now()))->toBeTrue();
    expect($event->fresh()->restored_by)->toBe($this->operator->id);
    expect($this->service->restricted($this->business))->toBeFalse();
    app(\App\Services\Business\BusinessOrderingRestrictionService::class)->sync($this->business);
    $this->assertDatabaseCount('business_ordering_restrictions', 1);
});
it('does not restore while another currency invoice remains overdue', function () {
    restrictionPolicy($this);
    $invoice = restrictionInvoice($this);
    $other = restrictionInvoice($this, ['currency' => 'USD', 'total' => 5]);
    $this->service->recordReceipt($invoice->id, 'payment', 300, 'TZS-PAID', null, $this->operator->id);
    $event = \App\Models\Business\BusinessOrderingRestriction::firstOrFail();
    expect($event->restored_at)->toBeNull();
    $this->getJson('/v1/operations/billing/restrictions')->assertOk()->assertJsonPath('data.restrictions.total', 1)->assertJsonPath('data.restrictions.data.0.balances.0.currency', 'USD');
    $this->service->recordReceipt($other->id, 'credit', 5, 'WAIVED', 'Approved credit', $this->operator->id);
    expect($event->fresh()->restoration_reason)->toBe('credit_applied');
});
it('records deadline extension and a new episode if its new deadline passes', function () {
    restrictionPolicy($this);
    $invoice = restrictionInvoice($this);
    $this->patchJson($this->url.'/invoices/'.$invoice->uuid.'/deadline', ['due_at' => now()->addDay()->toIso8601String(), 'reason' => 'Agreed extension'])->assertOk();
    $first = \App\Models\Business\BusinessOrderingRestriction::firstOrFail();
    expect($first->restoration_reason)->toBe('deadline_extended');
    Carbon::setTestNow(now()->addDay());
    app(\App\Services\Business\BusinessOrderingRestrictionService::class)->sync($this->business);
    $this->assertDatabaseCount('business_ordering_restrictions', 2);
    expect(\App\Models\Business\BusinessOrderingRestriction::where('active_business_id', $this->business->id)->count())->toBe(1);
    $this->getJson('/v1/operations/billing/restrictions?status=restored')->assertOk()->assertJsonPath('data.restrictions.total', 1)->assertJsonPath('data.restrictions.data.0.uuid', $first->uuid);
});
it('records disabling enforcement and re-enabling it without reusing an old episode', function () {
    restrictionPolicy($this);
    restrictionInvoice($this);
    $this->patchJson($this->url, [...$this->payload, 'automatic_invoicing' => true, 'enforce_overdue' => false, 'next_invoice_at' => now()->addWeek()->toIso8601String()])->assertOk();
    $first = \App\Models\Business\BusinessOrderingRestriction::firstOrFail();
    expect($first->restoration_reason)->toBe('enforcement_disabled');
    Carbon::setTestNow(now()->addHour());
    $this->patchJson($this->url, [...$this->payload, 'automatic_invoicing' => true, 'enforce_overdue' => true, 'next_invoice_at' => now()->addWeek()->toIso8601String()])->assertOk();
    $this->assertDatabaseCount('business_ordering_restrictions', 2);
    $active = \App\Models\Business\BusinessOrderingRestriction::where('active_business_id', $this->business->id)->firstOrFail();
    expect($active->restricted_at->equalTo(now()))->toBeTrue();
});
it('shows currently restricted businesses before reconciliation without inventing historical dates', function () {
    restrictionPolicy($this, false);
    restrictionInvoice($this, ['due_at' => now()->subDays(2)]);
    $this->getJson('/v1/operations/billing/restrictions')->assertOk()->assertJsonPath('data.restrictions.total', 1)->assertJsonPath('data.restrictions.data.0.restricted_at', null)->assertJsonPath('data.restrictions.data.0.date_basis', 'pending_sync');
    $this->assertDatabaseCount('business_ordering_restrictions', 0);
    $this->artisan('billing:sync-restrictions')->assertSuccessful();
    $event = \App\Models\Business\BusinessOrderingRestriction::firstOrFail();
    expect($event->date_basis)->toBe('first_observed');
    expect($event->restricted_at->equalTo(now()))->toBeTrue();
    $this->getJson('/v1/operations/billing')->assertOk()->assertJsonPath('data.counts.restricted_businesses', 1);
});
it('supports server-side filtering, pagination, read-only permission and staff denial', function () {
    restrictionPolicy($this);
    restrictionInvoice($this);
    app(\App\Services\Business\BusinessOrderingRestrictionService::class)->sync($this->business);
    $this->getJson('/v1/operations/billing/restrictions?search=Billing&per_page=15&from=2026-10-25&to=2026-10-25')->assertOk()->assertJsonPath('data.restrictions.total', 1)->assertJsonPath('data.restrictions.per_page', 15);
    $this->getJson('/v1/operations/billing/restrictions?search=Missing')->assertOk()->assertJsonPath('data.restrictions.total', 0);
    $this->getJson('/v1/operations/billing/restrictions?to=2026-10-25')->assertOk();
    $this->getJson('/v1/operations/billing/restrictions?per_page=12')->assertUnprocessable();
    Permission::findOrCreate('operations.settlements.view', 'api');
    $this->operator->givePermissionTo('operations.settlements.view');
    $this->operator->revokePermissionTo('operations.settlements.manage');
    $this->getJson('/v1/operations/billing/restrictions')->assertOk();
    $this->actingAs($this->owner, 'api')->getJson('/v1/operations/billing/restrictions')->assertForbidden();
    $this->operator->update(['type' => 'agent']);
    $this->actingAs($this->operator, 'api')->getJson('/v1/operations/billing/restrictions')->assertForbidden();
});
it('keeps a restriction record when a staff order attempt is rejected without creating an order', function () {
    restrictionPolicy($this);
    restrictionInvoice($this);
    try {
        app(\App\Repositories\Order\OrderRepository::class)->storeForStaff($this->business, ['channel' => 'dine_in'], $this->operator->id);
        $this->fail('Restricted order created');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(423);
    }
    $this->assertDatabaseCount('business_ordering_restrictions', 1);
    $this->assertDatabaseCount('orders', 0);
});
it('does not restrict an unsent invoice or disabled enforcement', function () {
    $policy = restrictionPolicy($this);
    $invoice = restrictionInvoice($this, ['sent_at' => null]);
    $tracking = app(\App\Services\Business\BusinessOrderingRestrictionService::class);
    $tracking->sync($this->business);
    $this->assertDatabaseCount('business_ordering_restrictions', 0);
    $policy->update(['enforce_overdue' => false]);
    $invoice->update(['sent_at' => now()->subDay()]);
    $tracking->sync($this->business);
    $this->getJson('/v1/operations/billing/restrictions')->assertOk()->assertJsonPath('data.restrictions.total', 0);
    expect($this->service->restricted($this->business))->toBeFalse();
});
