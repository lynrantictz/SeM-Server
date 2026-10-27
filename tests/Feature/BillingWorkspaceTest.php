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

it('counts all unpaid business invoices across pages and excludes settled or unrelated invoices', function () {
    $this->actingAs($this->owner, 'api');
    $url = '/v1/businesses/'.$this->business->uuid.'/billing';
    $this->getJson($url)->assertOk()->assertJsonPath('data.unpaid_invoice_count', 0);
    for ($i = 0; $i < 12; $i++) {
        workspaceInvoice($this, ['paid_amount' => $i === 0 ? 100 : 0, 'period_to' => now()->subDays($i)]);
    }
    workspaceInvoice($this, ['paid_amount' => 300, 'status' => 'paid', 'period_to' => now()->subDays(12)]);
    workspaceInvoice($this, ['credit_amount' => 300, 'status' => 'paid', 'period_to' => now()->subDays(13)]);
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->save();
    workspaceInvoice($this, ['business_id' => $other->id]);
    $this->getJson($url.'?per_page=10&page=2')->assertOk()
        ->assertJsonPath('data.unpaid_invoice_count', 12)
        ->assertJsonCount(4, 'data.invoices.data');
});

function billingWorkbookXml($response): string
{
    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    expect(simplexml_load_string($zip->getFromName('xl/workbook.xml')))->not->toBeFalse();
    $zip->close();
    unlink($path);
    expect(simplexml_load_string($xml))->not->toBeFalse();

    return $xml;
}

it('exports all matching outstanding invoices across pages with dates and numeric totals', function () {
    for ($i = 0; $i < 16; $i++) {
        workspaceInvoice($this, ['number' => 'EXPORT-'.$i, 'period_to' => now()->subDays($i), 'paid_amount' => $i === 0 ? 100 : 0]);
    }
    workspaceInvoice($this, ['number' => 'SETTLED', 'status' => 'paid', 'paid_amount' => 300, 'period_to' => now()->subDays(20)]);
    $this->getJson('/v1/operations/billing?status=outstanding')->assertOk()->assertJsonPath('data.rows.total', 16)->assertJsonStructure(['data' => ['rows' => ['data' => [['period_from', 'period_to', 'created_at', 'due_at', 'deadline_extended']]]]]);
    $response = $this->get('/v1/operations/billing/export?status=outstanding&search=EXPORT&per_page=10&page=2')->assertOk()->assertDownload('Paperstic-invoices.xlsx');
    $xml = billingWorkbookXml($response);
    expect(substr_count($xml, '<row>'))->toBe(17);
    expect($xml)->toContain('EXPORT-0', 'EXPORT-15', '<v>200</v>', 'Billing period start (UTC)', 'Payment due (UTC)')->not->toContain('SETTLED');
    $xml = billingWorkbookXml($this->get('/v1/operations/billing/export?status=paid')->assertOk());
    expect($xml)->toContain('SETTLED')->not->toContain('EXPORT-');
    $this->actingAs($this->owner, 'api')->get('/v1/operations/billing/export')->assertForbidden();
});

it('exports immutable invoice order fees even when the original order is unavailable and denies mismatched access', function () {
    $invoice = workspaceInvoice($this, ['number' => 'ORDER-EXPORT']);
    $invoice->items()->create(['order_platform_fee_id' => 99999, 'order_number' => '=UNTRUSTED()', 'base_amount' => 10000, 'rate' => 3, 'amount' => 300]);
    $response = $this->get($this->url.'/invoices/'.$invoice->uuid.'/orders/export')->assertOk()->assertDownload('Paperstic-ORDER-EXPORT-orders.xlsx');
    $xml = billingWorkbookXml($response);
    expect($xml)->toContain('=UNTRUSTED()', '<v>10000</v>', '<v>3</v>', '<v>300</v>')->not->toContain('<f>');
    $foreign = workspaceInvoice($this, ['business_id' => 99999, 'period_to' => now()->subMonth()]);
    $this->get($this->url.'/invoices/'.$foreign->uuid.'/orders/export')->assertNotFound();
    $this->actingAs($this->owner, 'api')->get($this->url.'/invoices/'.$invoice->uuid.'/orders/export')->assertForbidden();
});

it('filters invoice dates inclusively on the server and exports the same search and status matches', function () {
    $first = workspaceInvoice($this, ['number' => 'DATE-FIRST', 'created_at' => '2026-10-01 00:00:00', 'period_from' => '2026-09-24', 'period_to' => '2026-10-01', 'due_at' => '2026-10-04 23:59:59']);
    $last = workspaceInvoice($this, ['number' => 'DATE-LAST', 'created_at' => '2026-10-02 23:59:59', 'period_from' => '2026-10-01', 'period_to' => '2026-10-02', 'due_at' => '2026-10-05 00:00:00', 'paid_amount' => 100]);
    workspaceInvoice($this, ['number' => 'DATE-OUTSIDE', 'created_at' => '2026-10-03 00:00:00', 'period_from' => '2026-10-02', 'period_to' => '2026-10-03']);
    workspaceInvoice($this, ['number' => 'DATE-PAID', 'created_at' => '2026-10-02 12:00:00', 'period_to' => '2026-10-04', 'paid_amount' => 300, 'status' => 'paid']);
    $filters = 'view=invoices&status=outstanding&search=bILLING%20rESTAURANT&date_field=issued&from=2026-10-01&to=2026-10-02';
    $this->getJson('/v1/operations/billing?'.$filters)->assertOk()->assertJsonPath('data.rows.total', 2)->assertJsonPath('data.rows.data.0.uuid', $last->uuid);
    $xml = billingWorkbookXml($this->get('/v1/operations/billing/export?'.$filters)->assertOk());
    expect($xml)->toContain('DATE-FIRST', 'DATE-LAST')->not->toContain('DATE-OUTSIDE', 'DATE-PAID');
    $this->getJson('/v1/operations/billing?search=date-first')->assertOk()->assertJsonPath('data.rows.total', 1)->assertJsonPath('data.rows.data.0.uuid', $first->uuid);
    $this->getJson('/v1/operations/billing?date_field=due&from=2026-10-04&to=2026-10-04')->assertOk()->assertJsonPath('data.rows.total', 1)->assertJsonPath('data.rows.data.0.uuid', $first->uuid);
    $this->getJson('/v1/operations/billing?date_field=period&from=2026-10-01&to=2026-10-01')->assertOk()->assertJsonPath('data.rows.total', 1)->assertJsonPath('data.rows.data.0.uuid', $last->uuid);
    $this->getJson('/v1/operations/billing?from=2026-10-02&to=2026-10-01')->assertUnprocessable();
    $this->getJson('/v1/operations/billing/export?from=invalid')->assertUnprocessable();
});

it('paginates filtered invoices on the backend and searches payment and schedule business names case insensitively', function () {
    for ($i = 0; $i < 17; $i++) {
        $invoice = workspaceInvoice($this, ['number' => 'PAGINATED-'.$i, 'period_to' => now()->subDays($i)]);
    }
    $invoice->receipts()->create(['kind' => 'payment', 'amount' => 10, 'reference' => 'TEST-REF', 'recorded_by' => $this->operator->id]);
    $response = $this->getJson('/v1/operations/billing?view=invoices&status=outstanding&search=paginated&per_page=15&page=2')->assertOk()->assertJsonPath('data.rows.total', 17)->assertJsonPath('data.rows.current_page', 2)->assertJsonPath('data.rows.last_page', 2)->assertJsonCount(2, 'data.rows.data');
    $this->getJson('/v1/operations/billing?view=payments&search=bILLING%20rESTAURANT')->assertOk()->assertJsonPath('data.rows.total', 1);
    $this->getJson('/v1/operations/billing?view=schedules&search=bILLING%20rESTAURANT')->assertOk()->assertJsonPath('data.rows.total', 1);
});

it('serves business invoice tables with scoped filters sorting pagination and full Excel exports', function () {
    $this->actingAs($this->owner, 'api');
    $url = '/v1/businesses/'.$this->business->uuid.'/billing/invoices';
    for ($i = 0; $i < 16; $i++) {
        workspaceInvoice($this, ['number' => 'BUSINESS-'.str_pad($i, 2, '0', STR_PAD_LEFT), 'period_to' => now()->subDays($i), 'created_at' => '2026-10-24 23:59:59', 'paid_amount' => $i === 0 ? 100 : 0]);
    }
    workspaceInvoice($this, ['number' => 'BUSINESS-PAID', 'period_to' => now()->subDays(20), 'paid_amount' => 300, 'status' => 'paid']);
    $foreign = workspaceInvoice($this, ['number' => 'BUSINESS-FOREIGN', 'business_id' => 9999, 'period_to' => now()->subDays(21)]);
    $filters = '?status=outstanding&search=business&from=2026-10-24&to=2026-10-24&sort=number&direction=asc&per_page=15&page=2';
    $this->getJson($url.$filters)->assertOk()->assertJsonPath('data.invoices.total', 16)->assertJsonPath('data.invoices.current_page', 2)->assertJsonCount(1, 'data.invoices.data')->assertJsonPath('data.invoices.data.0.number', 'BUSINESS-15');
    $xml = billingWorkbookXml($this->get($url.'/export'.$filters)->assertOk()->assertDownload('Paperstic-invoices.xlsx'));
    expect(substr_count($xml, '<row>'))->toBe(17);
    expect($xml)->toContain('BUSINESS-00', 'BUSINESS-15')->not->toContain('BUSINESS-FOREIGN', 'BUSINESS-PAID');
    $this->getJson($url.'?status=paid')->assertOk()->assertJsonPath('data.invoices.total', 1);
    $this->getJson($url.'?from=2026-10-25&to=2026-10-24')->assertUnprocessable();
    $this->getJson($url.'?sort=unsafe')->assertUnprocessable();
    $this->getJson($url.'/'.$foreign->uuid.'/orders')->assertNotFound();
});

it('paginates and filters individual invoice order fees while retaining invoice snapshots in exports', function () {
    $this->actingAs($this->owner, 'api');
    $invoice = workspaceInvoice($this, ['number' => 'FEE-TABLE']);
    $status = DB::table('order_statuses')->first()->id;
    $method = DB::table('payment_methods')->insertGetId(['name' => 'Test cash']);
    $paymentStatus = DB::table('payment_statuses')->insertGetId(['name' => 'Test paid']);
    for ($i = 0; $i < 17; $i++) {
        $order = DB::table('orders')->insertGetId(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'number' => 'FEE-ORDER-'.$i, 'channel' => 'dine_in', 'order_status_id' => $status, 'payment_method_id' => $method, 'payment_status_id' => $paymentStatus, 'created_at' => '2026-10-24 23:59:59', 'total_items_amount' => 90000]);
        $fee = DB::table('order_platform_fees')->insertGetId(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'order_id' => $order, 'currency' => 'TZS', 'rate' => 9, 'base_amount' => 90000, 'amount' => 8100, 'reason' => 'standard']);
        $invoice->items()->create(['order_platform_fee_id' => $fee, 'order_number' => 'FEE-ORDER-'.$i, 'base_amount' => 10000, 'rate' => 3, 'amount' => 300]);
    }
    $url = '/v1/businesses/'.$this->business->uuid.'/billing/invoices/'.$invoice->uuid.'/orders';
    $filters = '?channel=dine_in&payment_method='.$method.'&order_status='.$status.'&payment_status='.$paymentStatus.'&from=2026-10-24&to=2026-10-24&sort=fee&direction=asc&per_page=15&page=2';
    $this->getJson($url.$filters)->assertOk()->assertJsonPath('data.orders.total', 17)->assertJsonPath('data.orders.current_page', 2)->assertJsonCount(2, 'data.orders.data')->assertJsonPath('data.orders.data.0.rate', 3)->assertJsonPath('data.orders.data.0.amount', 300)->assertJsonPath('data.options.methods.0.label', 'Test cash');
    $xml = billingWorkbookXml($this->get($url.'/export'.$filters)->assertOk()->assertDownload('Paperstic-FEE-TABLE-orders.xlsx'));
    expect(substr_count($xml, '<row>'))->toBe(18);
    expect($xml)->toContain('<v>10000</v>', '<v>3</v>', '<v>300</v>')->not->toContain('<v>8100</v>');
    $this->getJson($url.'?search=fee-order-16')->assertOk()->assertJsonPath('data.orders.total', 1);
    $this->getJson($url.'?payment_method=99999')->assertOk()->assertJsonPath('data.orders.total', 0);
    $this->getJson($url.'?from=2026-10-25')->assertOk()->assertJsonPath('data.orders.total', 0);
    $this->getJson($url.'?per_page=12')->assertUnprocessable();
});

it('denies business invoice table and export endpoints to unrelated owners agents and frontline staff', function () {
    $invoice = workspaceInvoice($this);
    $url = '/v1/businesses/'.$this->business->uuid.'/billing/invoices';
    $this->owner->vendors()->detach();
    $this->actingAs($this->owner, 'api');
    foreach ([$url, $url.'/export', $url.'/'.$invoice->uuid.'/orders', $url.'/'.$invoice->uuid.'/orders/export'] as $path) {
        $this->get($path)->assertForbidden();
    }
    $this->owner->update(['type' => 'business']);
    $this->owner->businesses()->attach($this->business->id, ['business_role' => 'waiter', 'title' => 'Waiter', 'is_active' => true]);
    $this->getJson($url)->assertForbidden();
    $this->owner->update(['type' => 'agent']);
    $this->getJson($url)->assertForbidden();
});

it('shows and exports the latest payment receiving account without duplicating invoice orders or exposing other businesses accounts', function () {
    $this->actingAs($this->owner, 'api');
    $invoice = workspaceInvoice($this, ['number' => 'ACCOUNT-FEES']);
    $method = DB::table('payment_methods')->insertGetId(['name' => 'Example bank']);
    $configuration = $this->business->paymentMethods()->create(['payment_method_id' => $method, 'is_enabled' => true, 'status' => 'active']);
    $old = $configuration->accounts()->create(['bank_name' => 'Earlier bank', 'account_number' => '111111', 'account_holder_name' => 'Owner', 'currency' => 'TZS']);
    $account = $configuration->accounts()->create(['bank_name' => 'Equity Bank', 'account_number' => '074937392', 'account_holder_name' => 'Registered Business', 'currency' => 'TZS']);
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->save();
    $foreignConfig = $other->paymentMethods()->create(['payment_method_id' => $method, 'is_enabled' => true, 'status' => 'active']);
    $foreign = $foreignConfig->accounts()->create(['bank_name' => 'Foreign bank', 'account_number' => '987654', 'account_holder_name' => 'Foreign owner', 'currency' => 'TZS']);
    $orderId = DB::table('orders')->insertGetId(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'number' => 'ORDER-ACCOUNT', 'payment_method_id' => $method, 'order_status_id' => 1, 'created_at' => now()]);
    $feeId = DB::table('order_platform_fees')->insertGetId(['uuid' => Str::uuid(), 'business_id' => $this->business->id, 'order_id' => $orderId, 'currency' => 'TZS', 'rate' => 3, 'base_amount' => 10000, 'amount' => 300, 'reason' => 'standard']);
    $invoice->items()->create(['order_platform_fee_id' => $feeId, 'order_number' => 'ORDER-ACCOUNT', 'base_amount' => 10000, 'rate' => 3, 'amount' => 300]);
    foreach ([$old, $account] as $paymentAccount) {
        DB::table('payments')->insert(['uuid' => Str::uuid(), 'order_id' => $orderId, 'external_id' => Str::random(), 'account_number' => 'bank_transfer', 'provider' => 'bank_transfer', 'amount' => 10000, 'currency' => 'TZS', 'status' => 'SUCCESS', 'metadata' => json_encode(['payment_account_uuid' => $paymentAccount->uuid])]);
    }
    $url = '/v1/businesses/'.$this->business->uuid.'/billing/invoices/'.$invoice->uuid.'/orders';
    $this->getJson($url)->assertOk()->assertJsonPath('data.orders.total', 1)->assertJsonPath('data.orders.data.0.receiving_account_name', 'Registered Business')->assertJsonPath('data.orders.data.0.receiving_account_number', '074937392')->assertJsonMissingPath('data.orders.data.0.order_id');
    $xml = billingWorkbookXml($this->get($url.'/export')->assertOk());
    expect($xml)->toContain('Registered Business', '074937392')->not->toContain('Equity Bank', 'Earlier bank');
    DB::table('payments')->insert(['uuid' => Str::uuid(), 'order_id' => $orderId, 'external_id' => Str::random(), 'account_number' => 'bank_transfer', 'provider' => 'bank_transfer', 'amount' => 10000, 'currency' => 'TZS', 'status' => 'SUCCESS', 'metadata' => json_encode(['payment_account_uuid' => $foreign->uuid])]);
    $this->getJson($url)->assertOk()->assertJsonPath('data.orders.total', 1)->assertJsonPath('data.orders.data.0.receiving_account_name', null)->assertJsonPath('data.orders.data.0.receiving_account_number', null);
    $this->actingAs($this->operator, 'api')->getJson($url)->assertForbidden();
});
