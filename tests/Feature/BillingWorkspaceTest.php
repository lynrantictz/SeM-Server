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
function workspaceInvoice($test, array $attributes = []): BusinessInvoice
{
    return BusinessInvoice::create([...['business_id' => $test->business->id, 'number' => 'WORK-'.Str::random(10), 'currency' => 'TZS', 'total' => 300, 'paid_amount' => 0, 'credit_amount' => 0, 'billing_email' => 'billing@example.test', 'period_from' => now()->subWeek(), 'period_to' => now(), 'status' => 'sent', 'sent_at' => now()->subDays(4), 'due_at' => now()->subDay()], ...$attributes]);
}
it('lists unpaid and overdue invoices with currency-separated totals and working filters', function () {
    $overdue = workspaceInvoice($this);
    workspaceInvoice($this, ['currency' => 'USD', 'total' => 5, 'due_at' => now()->addDay()]);
    workspaceInvoice($this, ['paid_amount' => 300, 'status' => 'paid', 'period_to' => now()->subWeek()]);
    $this->getJson('/v1/operations/billing?view=invoices&per_page=15')->assertOk()->assertJsonPath('data.rows.total', 3)->assertJsonPath('data.counts.unpaid', 2)->assertJsonPath('data.counts.overdue', 1)->assertJsonCount(2, 'data.balances');
    $this->getJson('/v1/operations/billing?status=overdue')->assertOk()->assertJsonPath('data.rows.total', 1)->assertJsonPath('data.rows.data.0.uuid', $overdue->uuid);
    $this->getJson('/v1/operations/billing?status=paid')->assertOk()->assertJsonPath('data.rows.total', 1);
    $this->getJson('/v1/operations/billing?search='.$overdue->number)->assertOk()->assertJsonPath('data.rows.total', 1);
    $this->getJson('/v1/operations/billing?search=NoSuchBusiness')->assertOk()->assertJsonPath('data.rows.total', 0);
    $this->getJson('/v1/operations/billing?per_page=12')->assertUnprocessable();
});
it('paginates billing payment history and excludes unrelated businesses', function () {
    $invoice = workspaceInvoice($this);
    for ($i = 0; $i < 16; $i++) {
        $invoice->receipts()->create(['kind' => 'payment', 'amount' => 1, 'reference' => 'REF-'.$i, 'recorded_by' => $this->operator->id]);
    }
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->name = 'Other business';
    $other->save();
    $foreign = workspaceInvoice($this, ['business_id' => $other->id]);
    $foreign->receipts()->create(['kind' => 'payment', 'amount' => 10, 'reference' => 'FOREIGN', 'recorded_by' => $this->operator->id]);
    $this->getJson('/v1/operations/billing?view=payments&per_page=15')->assertOk()->assertJsonPath('data.rows.total', 17)->assertJsonCount(15, 'data.rows.data');
    $this->getJson('/v1/operations/billing?view=payments&business_uuid='.$this->business->uuid)->assertOk()->assertJsonPath('data.rows.total', 16);
    $this->getJson('/v1/operations/billing?view=payments&search=FOREIGN')->assertOk()->assertJsonPath('data.rows.total', 1);
    $this->actingAs($this->owner, 'api')->getJson('/v1/businesses/'.$this->business->uuid.'/billing/payments?per_page=15&page=2')->assertOk()->assertJsonPath('data.payments.total', 16)->assertJsonCount(1, 'data.payments.data')->assertJsonMissing(['reference' => 'FOREIGN']);
});
it('includes businesses without schedules and enforces read-only finance access', function () {
    $this->getJson('/v1/operations/billing?view=schedules')->assertOk()->assertJsonPath('data.rows.data.0.business_uuid', $this->business->uuid)->assertJsonPath('data.rows.data.0.frequency', null);
    Permission::findOrCreate('operations.settlements.view', 'api');
    $this->operator->givePermissionTo('operations.settlements.view');
    $this->operator->revokePermissionTo('operations.settlements.manage');
    $this->getJson('/v1/operations/billing')->assertOk();
    $invoice = workspaceInvoice($this);
    $this->postJson($this->url.'/invoices/'.$invoice->uuid.'/receipts', ['kind' => 'payment', 'amount' => 300, 'reference' => 'NO-WRITE'])->assertForbidden();
    $this->operator->revokePermissionTo('operations.settlements.view');
    $this->getJson('/v1/operations/billing')->assertForbidden();
});
it('denies billing workspaces and payment history to frontline staff and agents', function () {
    $this->owner->update(['type' => 'business']);
    $this->owner->businesses()->attach($this->business->id, ['business_role' => 'waiter', 'title' => 'Waiter', 'is_active' => true]);
    $this->actingAs($this->owner, 'api')->getJson('/v1/businesses/'.$this->business->uuid.'/billing/payments')->assertForbidden();
    $this->getJson('/v1/operations/billing')->assertForbidden();
    $this->operator->update(['type' => 'agent']);
    $this->actingAs($this->operator, 'api')->getJson('/v1/operations/billing')->assertForbidden();
});
