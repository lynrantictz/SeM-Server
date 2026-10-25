<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\Location\City;
use App\Models\Location\Country;
use App\Models\Location\District;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 22:00:00 UTC'));
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $vendor = Vendor::create(['name' => 'Vendor', 'email' => 'dashboard@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Dashboard restaurant', 'vendor_id' => $vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->owner = User::create(['name' => 'Owner', 'email' => 'dashboard-owner@example.test', 'password' => 'Example@2026', 'type' => 'owner', 'is_active' => true]);
    $this->owner->vendors()->attach($vendor->id, ['is_primary' => true, 'is_active' => true, 'role' => 'owner', 'access_scope' => 'all_businesses']);
    $this->url = '/v1/businesses/'.$this->business->uuid.'/dashboard';
    $this->actingAs($this->owner, 'api');
});
afterEach(fn () => CarbonImmutable::setTestNow());

function dashboardOrder(Business $business, string $status, string $created = '2026-10-20 12:00:00', ?string $updated = null): Order
{
    $id = DB::table('order_statuses')->where('name', $status)->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => $status]);

    return Order::create(['business_id' => $business->id, 'number' => Str::random(12), 'order_status_id' => $id, 'total_amount' => 5000, 'created_at' => $created, 'updated_at' => $updated ?? $created]);
}
function dashboardCompletion(Order $order, string $at): void
{
    DB::table('order_status_histories')->insert(['uuid' => Str::uuid(), 'order_id' => $order->id, 'to_status_id' => $order->order_status_id, 'created_at' => $at, 'updated_at' => $at]);
}
function dashboardPayment(Order $order, string $currency, string $at, string $status = 'SUCCESS', bool $confirmed = true): void
{
    Payment::create(['order_id' => $order->id, 'external_id' => Str::random(12), 'account_number' => 'TEST', 'provider' => 'manual', 'amount' => 100, 'currency' => $currency, 'status' => $status, 'confirmed_at' => $confirmed ? $at : null, 'paid_at' => $confirmed ? null : $at]);
}
it('returns honest zero values and business local day for an empty business', function () {
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.date', '2026-10-26')->assertJsonPath('data.business.timezone', 'Africa/Dar_es_Salaam')->assertJsonPath('data.active_orders', 0)->assertJsonPath('data.completed_today', 0)->assertJsonPath('data.verified_payments_today', 0)->assertJsonPath('data.recent_orders', []);
});
it('counts all open orders and uses completion history rather than order creation or last edit', function () {
    foreach (['Pending', 'Processing', 'Ready', 'Awaiting payment', 'Scheduled', 'Served', 'Cancelled'] as $status) {
        dashboardOrder($this->business, $status);
    }
    $today = dashboardOrder($this->business, 'Completed');
    dashboardCompletion($today, '2026-10-25 21:00:00');
    $old = dashboardOrder($this->business, 'Completed', updated: '2026-10-25 22:00:00');
    dashboardCompletion($old, '2026-10-25 20:59:59');
    dashboardOrder($this->business, 'Completed', updated: '2026-10-25 22:00:00');
    $tomorrow = dashboardOrder($this->business, 'Completed');
    dashboardCompletion($tomorrow, '2026-10-26 21:00:00');
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.active_orders', 6)->assertJsonPath('data.pending_approval', 1)->assertJsonPath('data.completed_today', 2)->assertJsonPath('data.completed_today_legacy_count', 1);
});
it('counts only successful payments inside the local day and keeps currencies separate', function () {
    $order = dashboardOrder($this->business, 'Completed');
    dashboardPayment($order, 'TZS', '2026-10-25 21:00:00');
    dashboardPayment($order, 'USD', '2026-10-25 22:00:00', confirmed: false);
    dashboardPayment($order, 'TZS', '2026-10-25 20:59:59');
    dashboardPayment($order, 'TZS', '2026-10-26 21:00:00');
    dashboardPayment($order, 'TZS', '2026-10-25 22:00:00', 'PENDING');
    dashboardPayment($order, 'TZS', '2026-10-25 22:00:00', 'FAILED');
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.verified_payments_today', 2)->assertJsonCount(2, 'data.receipts_today')->assertJsonPath('data.receipts_today.0.currency', 'TZS')->assertJsonPath('data.receipts_today.0.amount', 100)->assertJsonPath('data.receipts_today.1.currency', 'USD');
});
it('limits recent orders to the selected business without exposing customer data', function () {
    for ($i = 0; $i < 8; $i++) {
        dashboardOrder($this->business, 'Pending', '2026-10-25 '.sprintf('%02d', $i).':00:00');
    }
    $other = $this->business->replicate(['uuid']);
    $other->order_prefix = Str::random(8);
    $other->code_prefix = Str::random(8);
    $other->save();
    $otherOrder = dashboardOrder($other, 'Pending', '2026-10-25 23:00:00');
    $this->getJson($this->url)->assertOk()->assertJsonCount(6, 'data.recent_orders')->assertJsonMissing(['number' => $otherOrder->number])->assertJsonMissingPath('data.recent_orders.0.customer')->assertJsonPath('data.active_orders', 8);
});
it('blocks inactive users and unrelated owners', function () {
    $this->owner->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    $this->owner->update(['is_active' => true]);
    $this->owner->vendors()->detach();
    $this->getJson($this->url)->assertForbidden();
});
it('allows managers but denies frontline roles access to owner figures', function (string $role, int $expected) {
    $this->owner->update(['type' => 'business']);
    DB::table('business_user')->insert(['user_id' => $this->owner->id, 'business_id' => $this->business->id, 'business_role' => $role, 'title' => 'Staff', 'is_active' => true]);
    $this->getJson($this->url)->assertStatus($expected);
})->with([['business_manager', 200], ['manager', 200], ['waiter', 403], ['counter', 403], ['kitchen', 403]]);
it('does not expose owner figures to onboarding agents', function () {
    $this->owner->update(['type' => 'agent']);
    $this->getJson($this->url)->assertForbidden();
});

it('restricts vendor managers to their assigned businesses', function () {
    $this->owner->vendors()->updateExistingPivot($this->business->vendor_id, ['is_primary' => false, 'role' => 'manager', 'access_scope' => 'selected_businesses']);
    $this->getJson($this->url)->assertForbidden();
    DB::table('business_user')->insert(['user_id' => $this->owner->id, 'business_id' => $this->business->id, 'business_role' => 'manager', 'title' => 'Manager', 'is_active' => true]);
    $this->getJson($this->url)->assertOk();
    DB::table('business_user')->where('user_id', $this->owner->id)->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
});
it('counts unpaid served orders without including already paid served orders', function () {
    $pending = DB::table('payment_statuses')->insertGetId(['name' => 'Pending']);
    $paid = DB::table('payment_statuses')->insertGetId(['name' => 'Completed']);
    dashboardOrder($this->business, 'Served')->update(['payment_status_id' => $pending]);
    dashboardOrder($this->business, 'Served')->update(['payment_status_id' => $paid]);
    dashboardOrder($this->business, 'Awaiting payment')->update(['payment_status_id' => $pending]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.awaiting_payment', 2)->assertJsonPath('data.active_orders', 3);
});
