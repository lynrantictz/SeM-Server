<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\OrderPlatformFee;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Services\Business\BusinessBillingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Carbon::setTestNow('2026-10-25 12:00:00 UTC');
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->vendor = Vendor::create(['name' => 'Billing vendor', 'email' => 'billing-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Billing restaurant', 'vendor_id' => $this->vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'timezone' => 'Africa/Dar_es_Salaam', 'is_active' => true, 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->business->paymentSetting()->create(['provider' => 'manual', 'currency' => 'TZS', 'commission_rate' => 3, 'commission_basis' => 'subtotal_excluding_tax', 'fee_bearer' => 'business', 'settlement_mode' => 'manual_hold']);
    $this->operator = User::create(['name' => 'Billing operator', 'email' => 'billing-ops@example.test', 'type' => 'paperstic', 'password' => 'Example@2026', 'is_active' => true]);
    foreach (['operations.businesses.view', 'operations.settlements.manage', 'operations.commissions.manage', 'operations.commissions.view'] as $permission) {
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

function commissionTestOrder($test, ?string $created = null): Order
{
    $status = DB::table('order_statuses')->where('name', 'Pending')->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => 'Pending']);
    $payment = DB::table('payment_statuses')->where('name', 'Pending')->value('id') ?? DB::table('payment_statuses')->insertGetId(['name' => 'Pending']);

    return Order::create(['business_id' => $test->business->id, 'number' => 'POL-'.Str::random(10), 'total_items_amount' => 10000, 'total_amount' => 11800, 'tax_amount' => 1800, 'due_amount' => 11800, 'channel' => 'online', 'order_status_id' => $status, 'payment_status_id' => $payment, ...($created ? ['created_at' => $created] : [])]);
}
function commissionTestPayload($test, array $changes = []): array
{
    $latest = \App\Models\Business\BusinessCommissionPolicy::where('business_id', $test->business->id)->where('state', 'published')->orderByDesc('starts_at')->orderByDesc('id')->first();

    return [...['rate' => 4, 'action' => 'publish', 'apply_now' => true, 'starts_at' => null, 'reason' => 'New commercial agreement', 'expected_policy_uuid' => $latest?->uuid], ...$changes];
}
function commissionTestUrl($test): string
{
    return '/v1/operations/businesses/'.$test->business->uuid.'/commission-policies';
}
it('links new fees to an immutable agreement and freezes global defaults per business', function () {
    $this->business->paymentSetting()->update(['commission_rate' => null]);
    $global = \App\Models\SystemSetting::where('key', 'payments.default_commission_rate')->firstOrFail();
    $global->changeValue('2');
    $first = commissionTestOrder($this);
    $global->changeValue('9');
    $second = commissionTestOrder($this);
    $fees = OrderPlatformFee::whereIn('order_id', [$first->id, $second->id])->get();
    expect($fees->pluck('business_commission_policy_id')->unique())->toHaveCount(1);
    expect((float) $fees[0]->rate)->toBe(2.0);
    expect((float) $fees[1]->rate)->toBe(2.0);
    expect($fees[0]->commissionPolicy->source)->toBe('system_default');
});
it('switches agreements at the exact scheduled boundary and keeps earlier orders unchanged', function () {
    $first = commissionTestOrder($this);
    $old = OrderPlatformFee::where('order_id', $first->id)->first();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['apply_now' => false, 'starts_at' => now()->addDay()->toIso8601String(), 'rate' => 5]))->assertOk();
    Carbon::setTestNow(now()->addDay()->subSecond());
    $before = commissionTestOrder($this);
    expect((float) OrderPlatformFee::where('order_id', $before->id)->value('rate'))->toBe(3.0);
    Carbon::setTestNow(now()->addSecond());
    $after = commissionTestOrder($this);
    $new = OrderPlatformFee::where('order_id', $after->id)->first();
    expect((float) $new->rate)->toBe(5.0);
    expect($new->business_commission_policy_id)->not->toBe($old->business_commission_policy_id);
    $first->update(['total_items_amount' => 20000]);
    expect((float) $old->fresh()->rate)->toBe(3.0);
    expect((float) $old->fresh()->amount)->toBe(600.0);
});
it('prevents backdated or overlapping replacements and rejects stale administrator changes', function () {
    commissionTestOrder($this);
    $stale = commissionTestPayload($this);
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['apply_now' => false, 'starts_at' => now()->subMinute()->toIso8601String()]))->assertUnprocessable();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['apply_now' => false, 'starts_at' => now()->addDays(7)->toIso8601String()]))->assertOk();
    $this->postJson(commissionTestUrl($this), $stale)->assertConflict();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['apply_now' => false, 'starts_at' => now()->addDays(3)->toIso8601String()]))->assertConflict();
});
it('allows drafts to be edited and published but rejects changes to published rates', function () {
    $url = commissionTestUrl($this);
    $uuid = $this->postJson($url, commissionTestPayload($this, ['action' => 'draft']))->assertOk()->json('data.policy.uuid');
    expect(app(\App\Services\Business\BusinessCommissionService::class)->current($this->business))->toBeNull();
    $this->patchJson($url.'/'.$uuid, commissionTestPayload($this, ['action' => 'draft', 'rate' => 6]))->assertOk();
    $this->patchJson($url.'/'.$uuid, commissionTestPayload($this, ['rate' => 6]))->assertOk();
    $this->patchJson($url.'/'.$uuid, commissionTestPayload($this, ['rate' => 8]))->assertConflict();
    $policy = \App\Models\Business\BusinessCommissionPolicy::where('uuid', $uuid)->firstOrFail();
    try {
        $policy->update(['rate' => 8]);
        $this->fail('Published rate mutated');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    try {
        $policy->delete();
        $this->fail('Published agreement deleted');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
});
it('cancels scheduled replacements without leaving a gap and preserves the next replacement', function () {
    commissionTestOrder($this);
    $url = commissionTestUrl($this);
    $uuid = $this->postJson($url, commissionTestPayload($this, ['rate' => 4, 'apply_now' => false, 'starts_at' => now()->addDays(3)->toIso8601String()]))->assertOk()->json('data.policy.uuid');
    $this->postJson($url, commissionTestPayload($this, ['rate' => 5, 'apply_now' => false, 'starts_at' => now()->addDays(7)->toIso8601String()]))->assertOk();
    $this->postJson($url.'/'.$uuid.'/cancel', ['reason' => 'Commercial change withdrawn'])->assertOk();
    Carbon::setTestNow(now()->addDays(4));
    expect(app(\App\Services\Business\BusinessCommissionService::class)->rate($this->business))->toBe(3.0);
    Carbon::setTestNow(now()->addDays(3));
    expect(app(\App\Services\Business\BusinessCommissionService::class)->rate($this->business))->toBe(5.0);
});
it('keeps zero rates and free-period exemptions related to their agreement', function () {
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['rate' => 0]))->assertOk();
    $zero = commissionTestOrder($this);
    $fee = OrderPlatformFee::where('order_id', $zero->id)->first();
    expect((float) $fee->amount)->toBe(0.0);
    expect($fee->reason)->toBe('no_commission');
    expect($fee->business_commission_policy_id)->not->toBeNull();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['rate' => 4]))->assertOk();
    BusinessBillingPolicy::create(['business_id' => $this->business->id, 'trial_days' => 14, 'trial_started_at' => now()]);
    $free = commissionTestOrder($this);
    $fee = OrderPlatformFee::where('order_id', $free->id)->first();
    expect((float) $fee->amount)->toBe(0.0);
    expect($fee->reason)->toBe('free_period');
    expect((float) $fee->commissionPolicy->rate)->toBe(4.0);
});
it('requires commission permission and scopes management and order-fee history', function () {
    commissionTestOrder($this);
    $url = commissionTestUrl($this);
    $this->getJson($url.'?per_page=15')->assertOk()->assertJsonPath('data.policies.per_page', 15);
    $policy = \App\Models\Business\BusinessCommissionPolicy::firstOrFail();
    $this->actingAs($this->owner, 'api')->getJson('/v1/businesses/'.$this->business->uuid.'/commission-policies/'.$policy->uuid.'/fees')->assertOk()->assertJsonCount(1, 'data.fees.data');
    $this->postJson($url, commissionTestPayload($this))->assertForbidden();
    $this->actingAs($this->operator, 'api');
    $this->operator->revokePermissionTo('operations.commissions.manage');
    $this->getJson($url)->assertOk();
    $this->postJson($url, commissionTestPayload($this))->assertForbidden();
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->save();
    $this->getJson('/v1/operations/businesses/'.$other->uuid.'/commission-policies/'.$policy->uuid.'/fees')->assertNotFound();
});
it('rejects the retired commission editors and invalid rates', function () {
    $this->patchJson($this->url, [...$this->payload, 'commission_rate' => 4])->assertUnprocessable()->assertJsonValidationErrors('commission_rate');
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['rate' => -1]))->assertUnprocessable();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['rate' => 101]))->assertUnprocessable();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['rate' => 1.234]))->assertUnprocessable();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['reason' => '']))->assertUnprocessable();
});
it('backfills only cutover policies and leaves historical order snapshots unrelated and unchanged', function () {
    $order = commissionTestOrder($this);
    $fee = OrderPlatformFee::where('order_id', $order->id)->first();
    $migration = require database_path('migrations/2026_10_25_000002_create_business_commission_policies.php');
    $migration->down();
    Carbon::setTestNow(now()->addDay());
    $migration->up();
    $fee = $fee->fresh();
    expect($fee->business_commission_policy_id)->toBeNull();
    expect((float) $fee->amount)->toBe(300.0);
    expect((float) $fee->rate)->toBe(3.0);
    $policy = \App\Models\Business\BusinessCommissionPolicy::where('business_id', $this->business->id)->firstOrFail();
    expect($policy->starts_at->equalTo(now()))->toBeTrue();
    expect((float) $policy->rate)->toBe(3.0);
    $new = commissionTestOrder($this);
    expect(OrderPlatformFee::where('order_id', $new->id)->value('business_commission_policy_id'))->toBe($policy->id);
});
it('cannot cancel a policy in use or move an order fee to another agreement', function () {
    $order = commissionTestOrder($this);
    $fee = OrderPlatformFee::where('order_id', $order->id)->firstOrFail();
    $this->postJson(commissionTestUrl($this).'/'.$fee->commissionPolicy->uuid.'/cancel', ['reason' => 'Attempted retrospective change'])->assertConflict();
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['rate' => 6]))->assertOk();
    $next = app(\App\Services\Business\BusinessCommissionService::class)->current($this->business);
    try {
        $fee->update(['business_commission_policy_id' => $next->id]);
        $this->fail('Fee relationship changed');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    try {
        $fee->fresh()->update(['rate' => 6]);
        $this->fail('Fee rate changed');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
});
it('keeps the original policy rate through later payment confirmation', function () {
    $order = commissionTestOrder($this);
    DB::table('payment_statuses')->insertOrIgnore(['name' => 'Completed']);
    $served = DB::table('order_statuses')->where('name', 'Served')->value('id') ?? DB::table('order_statuses')->insertGetId(['name' => 'Served']);
    $order->update(['order_status_id' => $served]);
    $this->postJson(commissionTestUrl($this), commissionTestPayload($this, ['rate' => 9]))->assertOk();
    $method = \App\Models\Payment\PaymentMethod::create(['name' => 'Cash', 'code' => 'cash']);
    app(\App\Services\PaymentGateway\PaymentSettlementService::class)->recordManualPayment($order, $method->id, 11800, 'FROZEN-AGREEMENT', $this->operator->id);
    $allocation = \App\Models\Payment\PaymentAllocation::firstOrFail();
    expect((float) $allocation->commission_rate)->toBe(3.0);
    expect((float) $allocation->commission_amount)->toBe(300.0);
});
