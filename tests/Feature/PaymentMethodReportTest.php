<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\OrderPlatformFee;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $vendor = Vendor::create(['name' => 'Vendor', 'email' => 'payment-report@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Restaurant', 'vendor_id' => $vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->owner = User::create(['name' => 'Owner', 'email' => 'payment-report-owner@example.test', 'password' => 'Example@2026', 'type' => 'owner', 'is_active' => true]);
    $this->owner->vendors()->attach($vendor->id, ['is_primary' => true, 'is_active' => true]);
    $this->method = DB::table('payment_methods')->insertGetId(['name' => 'M-Pesa']);
    $this->url = '/v1/businesses/'.$this->business->uuid.'/reports?view=payments&date_from=2026-10-26&date_to=2026-10-26';
    $this->actingAs($this->owner, 'api');
});
function reportOrder($test, array $changes = [], ?float $rate = 1): Order
{
    $order = new Order;
    $order->forceFill(['uuid' => Str::uuid(), 'number' => 'REPORT-'.Str::random(10), 'business_id' => $test->business->id, 'order_status_id' => DB::table('order_statuses')->where('name', 'Ready')->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => 'Ready']), 'payment_status_id' => DB::table('payment_statuses')->where('name', 'Completed')->value('id') ?? DB::table('payment_statuses')->insertGetId(['name' => 'Completed']), 'payment_method_id' => $test->method, 'total_amount' => 10000, 'total_items_amount' => 10000, 'paid_amount' => 10000, 'due_amount' => 0, 'created_at' => '2026-10-25 22:00:00', ...$changes]);
    $order->saveQuietly();
    if ($rate !== null) {
        OrderPlatformFee::create(['order_id' => $order->id, 'business_id' => $test->business->id, 'currency' => 'TZS', 'rate' => $rate, 'base_amount' => 10000, 'amount' => 10000 * $rate / 100, 'reason' => $rate === 0.0 ? 'zero_rate' : 'standard', 'status' => $rate === 0.0 ? 'waived' : 'estimated']);
    }

    return $order;
}
it('counts manual orders once despite retries and uses saved rates and local dates', function () {
    $order = reportOrder($this);
    foreach (['FAILED', 'SUCCESS'] as $status) {
        Payment::create(['order_id' => $order->id, 'external_id' => Str::random(12), 'account_number' => 'TEST', 'provider' => 'manual', 'amount' => 10000, 'currency' => 'TZS', 'status' => $status]);
    }
    reportOrder($this, rate: 0);
    reportOrder($this, rate: null);
    reportOrder($this, ['created_at' => '2026-10-25 20:59:59']);
    reportOrder($this, ['created_at' => '2026-10-26 21:00:00']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.timezone', 'Africa/Dar_es_Salaam')->assertJsonPath('data.rows.data.0.orders', 3)->assertJsonPath('data.rows.data.0.confirmed_amount', 30000)->assertJsonPath('data.rows.data.0.commission_estimated', 100)->assertJsonPath('data.rows.data.0.commission_billable', 0)->assertJsonPath('data.rows.data.0.missing_fees', 1)->assertJsonCount(2, 'data.rows.data.0.rates');
});
it('paginates and searches matching orders with status filters and recorded zero fees', function () {
    $zero = reportOrder($this, ['number' => 'REPORT-ZERO'], 0);
    for ($i = 0; $i < 15; $i++) {
        reportOrder($this);
    }
    $detail = $this->url.'&payment_method_key='.$this->method.'&currency=TZS';
    $this->getJson($detail.'&per_page=15&page=2')->assertOk()->assertJsonPath('data.rows.meta.total', 16)->assertJsonCount(1, 'data.rows.data');
    $this->getJson($detail.'&search=report-zero')->assertOk()->assertJsonPath('data.rows.meta.total', 1)->assertJsonPath('data.rows.data.0.number', $zero->number)->assertJsonPath('data.rows.data.0.fee_status', 'waived')->assertJsonPath('data.rows.data.0.rate', 0)->assertJsonPath('data.rows.data.0.commission_amount', 0);
    $this->getJson($detail.'&payment_status=Pending')->assertOk()->assertJsonPath('data.rows.meta.total', 0);
    $this->getJson($detail.'&order_status=Ready')->assertOk()->assertJsonPath('data.rows.meta.total', 16);
});
it('enforces business access and rejects invalid filters', function () {
    reportOrder($this);
    $staff = User::create(['name' => 'Waiter', 'email' => 'report-waiter@example.test', 'password' => 'Example@2026', 'type' => 'business', 'is_active' => true]);
    $staff->businesses()->attach($this->business->id, ['business_role' => 'waiter', 'title' => 'Waiter', 'is_active' => true]);
    $this->actingAs($staff, 'api')->getJson($this->url)->assertForbidden();
    $staff->businesses()->updateExistingPivot($this->business->id, ['business_role' => 'manager']);
    $this->getJson($this->url)->assertOk();
    $this->getJson($this->url.'&per_page=11')->assertUnprocessable();
    $this->getJson($this->url.'&date_from=2026-10-27')->assertUnprocessable();
    $this->actingAs($this->owner, 'api');
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->save();
    reportOrder($this, ['business_id' => $other->id, 'number' => 'FOREIGN-ORDER']);
    $this->getJson($this->url.'&payment_method_key='.$this->method.'&search=FOREIGN')->assertOk()->assertJsonPath('data.rows.meta.total', 0);
});

it('separates currencies pending balances and billable fees from estimates and credits', function () {
    $accrued = reportOrder($this, rate: 2);
    OrderPlatformFee::where('order_id', $accrued->id)->update(['status' => 'accrued']);
    $credited = reportOrder($this, rate: 1);
    OrderPlatformFee::where('order_id', $credited->id)->update(['status' => 'credited']);
    $pendingId = DB::table('payment_statuses')->insertGetId(['name' => 'Pending']);
    reportOrder($this, ['payment_status_id' => $pendingId, 'paid_amount' => 0, 'due_amount' => 10000], 3);
    $usd = reportOrder($this, rate: 1);
    DB::table('order_platform_fees')->where('order_id', $usd->id)->update(['currency' => 'USD', 'amount' => 5]);
    $response = $this->getJson($this->url)->assertOk()->assertJsonPath('data.rows.meta.total', 2);
    $tzs = collect($response->json('data.rows.data'))->firstWhere('currency', 'TZS');
    expect((float) $tzs['confirmed_amount'])->toBe(20000.0);
    expect((float) $tzs['pending_amount'])->toBe(10000.0);
    expect((float) $tzs['commission_billable'])->toBe(200.0);
    expect((float) $tzs['commission_estimated'])->toBe(300.0);
    expect((float) $tzs['commission_credited'])->toBe(100.0);
    $this->getJson($this->url.'&payment_method_key='.$this->method.'&currency=USD')->assertOk()->assertJsonPath('data.rows.meta.total', 1);
    $this->getJson($this->url.'&payment_method_key='.$this->method.'&currency=TZS&payment_status=Pending')->assertOk()->assertJsonPath('data.rows.meta.total', 1);
});
