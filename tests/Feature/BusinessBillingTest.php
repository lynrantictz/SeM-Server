<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessBillingPolicy;
use App\Models\Business\BusinessInvoice;
use App\Models\Business\OrderPlatformFee;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Models\Order\Order;
use App\Models\Payment\PaymentMethod;
use App\Notifications\BusinessInvoiceNotification;
use App\Services\Business\BusinessBillingService;
use App\Services\PaymentGateway\PaymentSettlementService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
function billingStatus(string $name, string $table): int
{
    return DB::table($table)->where('name', $name)->value('id') ?? DB::table($table)->insertGetId(['name' => $name]);
}
function billingOrder(Business $business, string $status = 'Pending', string $payment = 'Pending', float $base = 10000): Order
{
    return Order::create(['business_id' => $business->id, 'number' => 'BILL-'.Str::random(12), 'channel' => 'dine_in', 'total_items_amount' => $base, 'total_amount' => $base + 1800, 'tax_amount' => 1800, 'due_amount' => $payment === 'Completed' ? 0 : $base + 1800, 'order_status_id' => billingStatus($status, 'order_statuses'), 'payment_status_id' => billingStatus($payment, 'payment_statuses')]);
}
function enableBilling($test, array $changes = []): BusinessBillingPolicy
{
    $test->patchJson($test->url, [...$test->payload, 'automatic_invoicing' => true, 'enforce_overdue' => true, 'next_invoice_at' => now()->addHour()->toIso8601String(), ...$changes])->assertOk();

    return BusinessBillingPolicy::where('business_id', $test->business->id)->firstOrFail();
}
function billingInvoice($test): BusinessInvoice
{
    $policy = enableBilling($test);
    billingOrder($test->business, 'Completed', 'Completed');
    Carbon::setTestNow(now()->addHours(2));

    return $test->service->generate($policy)[0];
}
it('starts with automatic delivery and overdue restrictions disabled', function () {
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.policy.automatic_invoicing', false)->assertJsonPath('data.policy.enforce_overdue', false)->assertJsonPath('data.policy.grace_hours', 72);
    expect($this->service->restricted($this->business))->toBeFalse();
});
function billingReplaceRate($test, float $rate): void
{
    $service = app(\App\Services\Business\BusinessCommissionService::class);
    $latest = \App\Models\Business\BusinessCommissionPolicy::where('business_id', $test->business->id)->where('state', 'published')->orderByDesc('starts_at')->orderByDesc('id')->first();
    $service->save($test->business, ['rate' => $rate, 'action' => 'publish', 'apply_now' => true, 'reason' => 'Approved test agreement', 'expected_policy_uuid' => $latest?->uuid], $test->operator->id);
}
it('keeps existing snapshots while a zero commission replacement affects new orders', function () {
    $order = billingOrder($this->business);
    billingReplaceRate($this, 0);
    $new = billingOrder($this->business);
    expect((float) OrderPlatformFee::where('order_id', $order->id)->value('rate'))->toBe(3.0);
    expect((float) OrderPlatformFee::where('order_id', $new->id)->value('amount'))->toBe(0.0);
});
it('calculates the fee before tax and requires completed service plus confirmed payment to accrue', function () {
    $order = billingOrder($this->business);
    $fee = OrderPlatformFee::where('order_id', $order->id)->firstOrFail();
    expect((float) $fee->amount)->toBe(300.0);
    expect($fee->status)->toBe('estimated');
    $order->update(['payment_status_id' => billingStatus('Completed', 'payment_statuses')]);
    expect($fee->fresh()->status)->toBe('estimated');
    $order->update(['order_status_id' => billingStatus('Completed', 'order_statuses')]);
    expect($fee->fresh()->status)->toBe('accrued');
    expect($fee->fresh()->billable_at)->not->toBeNull();
});
it('rounds each fee to cents and freezes the rate while order amounts change', function () {
    $this->business->paymentSetting()->update(['commission_rate' => 2.5]);
    $order = billingOrder($this->business, base: 333.33);
    $this->business->paymentSetting()->update(['commission_rate' => 9]);
    expect((float) OrderPlatformFee::where('order_id', $order->id)->value('amount'))->toBe(8.33);
    $order->update(['total_items_amount' => 500]);
    $fee = OrderPlatformFee::where('order_id', $order->id)->first();
    expect((float) $fee->rate)->toBe(2.5);
    expect((float) $fee->amount)->toBe(12.5);
});
it('keeps trial orders free even after the trial ends and charges new orders at the exact boundary', function () {
    enableBilling($this, ['trial_days' => 7]);
    $free = billingOrder($this->business);
    Carbon::setTestNow(now()->addDays(7));
    $charged = billingOrder($this->business, 'Completed', 'Completed');
    $free->update(['order_status_id' => billingStatus('Completed', 'order_statuses'), 'payment_status_id' => billingStatus('Completed', 'payment_statuses')]);
    expect((float) OrderPlatformFee::where('order_id', $free->id)->value('amount'))->toBe(0.0);
    expect(OrderPlatformFee::where('order_id', $free->id)->value('reason'))->toBe('free_period');
    expect((float) OrderPlatformFee::where('order_id', $charged->id)->value('amount'))->toBe(300.0);
});
it('starts pending-business trials once at activation and supports a scheduled fee start', function () {
    $this->business->update(['is_active' => false]);
    $policy = enableBilling($this, ['trial_days' => 14]);
    expect($policy->trial_started_at)->toBeNull();
    $this->business->update(['is_active' => true]);
    $this->service->startTrial($this->business);
    $start = $policy->fresh()->trial_started_at->toIso8601String();
    Carbon::setTestNow(now()->addDay());
    $this->service->startTrial($this->business);
    expect($policy->fresh()->trial_started_at->toIso8601String())->toBe($start);
    $policy->update(['fee_starts_at' => now()->addDay()]);
    $free = billingOrder($this->business);
    expect(OrderPlatformFee::where('order_id', $free->id)->value('reason'))->toBe('free_period');
});
it('prevents duplicate manual payments and duplicate fee allocations', function () {
    $order = billingOrder($this->business, 'Served');
    billingStatus('Completed', 'payment_statuses');
    $method = PaymentMethod::create(['name' => 'Cash', 'code' => 'cash']);
    app(PaymentSettlementService::class)->recordManualPayment($order, $method->id, 11800, 'TEST-1', $this->operator->id);
    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    try {
        app(PaymentSettlementService::class)->recordManualPayment($order, $method->id, 11800, 'TEST-1', $this->operator->id);
    } finally {
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
    }
});
it('does not invoice unpaid uncompleted cancelled or zero-fee orders', function () {
    $policy = enableBilling($this);
    billingOrder($this->business, 'Pending', 'Pending');
    billingOrder($this->business, 'Processing', 'Completed');
    billingOrder($this->business, 'Cancelled', 'Completed');
    billingReplaceRate($this, 0);
    billingOrder($this->business, 'Completed', 'Completed');
    Carbon::setTestNow(now()->addHours(2));
    expect($this->service->generate($policy))->toBe([]);
    $this->assertDatabaseCount('business_invoices', 0);
});
it('invoices each eligible order once and never mixes currencies', function () {
    $policy = enableBilling($this);
    billingOrder($this->business, 'Completed', 'Completed');
    $this->business->paymentSetting()->update(['currency' => 'USD']);
    billingOrder($this->business, 'Completed', 'Completed');
    Carbon::setTestNow(now()->addHours(2));
    expect($this->service->generate($policy))->toHaveCount(2);
    $this->service->generate($policy);
    $this->assertDatabaseCount('business_invoices', 2);
    $this->assertDatabaseCount('business_invoice_items', 2);
});
it('advances monthly schedules without losing the original month-end day', function () {
    Carbon::setTestNow('2026-01-30 12:00:00 UTC');
    $policy = enableBilling($this, ['frequency' => 'monthly', 'next_invoice_at' => '2026-01-31T12:00:00Z']);
    Carbon::setTestNow('2026-01-31 13:00:00 UTC');
    $this->service->generate($policy);
    expect($policy->fresh()->next_invoice_at->format('Y-m-d'))->toBe('2026-02-28');
    Carbon::setTestNow('2026-02-28 13:00:00 UTC');
    $this->service->generate($policy->fresh());
    expect($policy->fresh()->next_invoice_at->format('Y-m-d'))->toBe('2026-03-31');
});
it('starts the 72-hour deadline only after invoice email dispatch', function () {
    Notification::fake();
    $invoice = billingInvoice($this);
    expect($invoice->sent_at)->toBeNull();
    expect($invoice->due_at)->toBeNull();
    expect($this->service->dispatchInvoice($invoice))->toBeTrue();
    $sent = $invoice->fresh();
    expect($sent->due_at->equalTo(now()->addHours(72)))->toBeTrue();
    Notification::assertSentOnDemand(BusinessInvoiceNotification::class, function ($notification, $channels, $notifiable) use ($sent) {
        expect($notifiable->routes['mail'])->toBe('billing-owner@example.test');
        expect($notification->invoice->due_at->equalTo($sent->due_at))->toBeTrue();
        expect($notification->invoice->items)->toHaveCount(1);

        return true;
    });
    expect($this->service->dispatchInvoice($sent))->toBeFalse();
});
it('retains an undelivered invoice without starting its deadline or blocking ordering', function () {
    $invoice = billingInvoice($this);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Test delivery failure'));
    expect($this->service->dispatchInvoice($invoice))->toBeFalse();
    expect($invoice->fresh()->sent_at)->toBeNull();
    expect($invoice->fresh()->due_at)->toBeNull();
    Carbon::setTestNow(now()->addDays(4));
    expect($this->service->restricted($this->business))->toBeFalse();
    expect($invoice->fresh()->delivery_error)->toContain('Test delivery failure');
});
it('blocks only new orders at the exact deadline and restores ordering after verified payment', function () {
    Notification::fake();
    $invoice = billingInvoice($this);
    $this->service->dispatchInvoice($invoice);
    Carbon::setTestNow($invoice->fresh()->due_at->copy()->subSecond());
    expect($this->service->restricted($this->business))->toBeFalse();
    Carbon::setTestNow(now()->addSecond());
    expect($this->service->restricted($this->business))->toBeTrue();
    try {
        billingOrder($this->business);
        $this->fail('New order was allowed');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(423);
    }
    $this->actingAs($this->owner, 'api')->getJson('/v1/businesses/'.$this->business->uuid)->assertOk();
    $this->getJson('/v1/businesses/'.$this->business->uuid.'/billing')->assertOk();
    $this->actingAs($this->operator, 'api')->postJson($this->url.'/invoices/'.$invoice->uuid.'/receipts', ['kind' => 'payment', 'amount' => 300, 'reference' => 'PAID-1'])->assertOk();
    expect($this->service->restricted($this->business))->toBeFalse();
    billingOrder($this->business);
    expect((bool) $this->business->fresh()->is_active)->toBeTrue();
});
it('supports partial payments, idempotent references and manual grace extensions', function () {
    Notification::fake();
    $invoice = billingInvoice($this);
    $this->service->dispatchInvoice($invoice);
    Carbon::setTestNow(now()->addDays(4));
    $url = $this->url.'/invoices/'.$invoice->uuid;
    $this->postJson($url.'/receipts', ['kind' => 'payment', 'amount' => 100, 'reference' => 'PART-1'])->assertOk();
    $this->postJson($url.'/receipts', ['kind' => 'payment', 'amount' => 100, 'reference' => 'PART-1'])->assertOk();
    expect((float) $invoice->fresh()->paid_amount)->toBe(100.0);
    expect($this->service->restricted($this->business))->toBeTrue();
    $this->postJson($url.'/receipts', ['kind' => 'payment', 'amount' => 500, 'reference' => 'OVERPAY'])->assertUnprocessable();
    $this->patchJson($url.'/deadline', ['due_at' => now()->addDay()->toIso8601String(), 'reason' => 'Agreed payment extension'])->assertOk();
    expect($this->service->restricted($this->business))->toBeFalse();
});
it('credits invoiced reversed orders once and carries paid-invoice credit into the next invoice', function () {
    Notification::fake();
    $invoice = billingInvoice($this);
    $this->service->dispatchInvoice($invoice);
    $this->service->recordReceipt($invoice->id, 'payment', 300, 'PAID-1', null, $this->operator->id);
    $fee = OrderPlatformFee::where('business_id', $this->business->id)->firstOrFail();
    $order = $fee->order;
    $order->update(['order_status_id' => billingStatus('Refunded', 'order_statuses')]);
    $this->service->syncFee($order);
    expect((float) $invoice->fresh()->credit_amount)->toBe(300.0);
    expect($invoice->receipts()->where('kind', 'credit')->count())->toBe(1);
    billingOrder($this->business, 'Completed', 'Completed');
    $policy = BusinessBillingPolicy::first();
    Carbon::setTestNow($policy->next_invoice_at->copy()->addHour());
    $next = $this->service->generate($policy)[0];
    expect($next->outstanding())->toBe(0.0);
    expect((float) $next->credit_amount)->toBe(300.0);
});
it('enforces billing permissions and business scope for settings and invoice receipts', function () {
    $this->actingAs($this->owner, 'api')->patchJson($this->url, $this->payload)->assertForbidden();
    $this->getJson('/v1/businesses/'.$this->business->uuid.'/billing')->assertOk();
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->save();
    $otherVendor = Vendor::create(['name' => 'Unrelated', 'email' => 'unrelated@example.test', 'country_id' => $this->vendor->country_id]);
    $other->update(['vendor_id' => $otherVendor->id]);
    $this->getJson('/v1/businesses/'.$other->uuid.'/billing')->assertForbidden();
    $this->owner->update(['type' => 'business']);
    $this->owner->businesses()->attach($this->business->id, ['business_role' => 'waiter', 'title' => 'Waiter', 'is_active' => true]);
    $this->getJson('/v1/businesses/'.$this->business->uuid.'/billing')->assertForbidden();
});
it('validates schedules and requires invoice delivery before enforcement can be enabled', function () {
    $this->patchJson($this->url, [...$this->payload, 'enforce_overdue' => true])->assertUnprocessable();
    $this->patchJson($this->url, [...$this->payload, 'automatic_invoicing' => true])->assertUnprocessable()->assertJsonValidationErrors('next_invoice_at');
    $this->patchJson($this->url, [...$this->payload, 'commission_rate' => -1])->assertUnprocessable();
    $this->patchJson($this->url, [...$this->payload, 'trial_days' => -1])->assertUnprocessable();
});
it('sends due reminders at most once per day', function () {
    Notification::fake();
    $invoice = billingInvoice($this);
    $this->service->dispatchInvoice($invoice);
    Carbon::setTestNow($invoice->fresh()->due_at->copy()->subHours(12));
    expect($this->service->dispatchInvoice($invoice, true))->toBeTrue();
    expect($this->service->dispatchInvoice($invoice, true))->toBeFalse();
    Notification::assertCount(2);
});
it('processes scheduled invoices once and renders a summary email without changing customer totals', function () {
    Notification::fake();
    $policy = enableBilling($this, ['frequency' => 'custom', 'interval_days' => 14]);
    $order = billingOrder($this->business, 'Completed', 'Completed');
    Carbon::setTestNow(now()->addHours(2));
    $this->artisan('billing:process')->assertSuccessful();
    $this->artisan('billing:process')->assertSuccessful();
    $invoice = BusinessInvoice::firstOrFail()->load(['business', 'items']);
    expect(BusinessInvoice::count())->toBe(1);
    Notification::assertCount(1);
    expect($policy->fresh()->next_invoice_at->diffInDays($invoice->period_to, true))->toBe(14.0);
    $html = (new BusinessInvoiceNotification($invoice))->toMail(new stdClass)->render();
    expect((string) $html)->toContain('Eligible orders', 'Subtotal excluding tax', 'TZS 300.00', $invoice->due_at->copy()->setTimezone('Africa/Dar_es_Salaam')->format('d M Y H:i'))->not->toContain($order->number);
    expect((float) $order->fresh()->total_amount)->toBe(11800.0);
});
it('scopes invoice details and keeps internal email errors out of the business response', function () {
    $invoice = billingInvoice($this);
    $invoice->update(['delivery_error' => 'Private transport credential failure']);
    $this->getJson($this->url.'/invoices/'.$invoice->uuid)->assertOk()->assertJsonCount(1, 'data.invoice.items');
    $this->actingAs($this->owner, 'api')->getJson('/v1/businesses/'.$this->business->uuid.'/billing/invoices/'.$invoice->uuid)
        ->assertOk()->assertJsonPath('data.invoice.delivery_error', 'Email dispatch needs attention.');
    $this->postJson($this->url.'/invoices/'.$invoice->uuid.'/receipts', ['kind' => 'payment', 'amount' => 300, 'reference' => 'UNAUTHORIZED'])->assertForbidden();
    $other = $this->business->replicate(['uuid']);
    $other->code_prefix = Str::random(8);
    $other->order_prefix = Str::random(8);
    $other->save();
    $this->actingAs($this->operator, 'api')->getJson('/v1/operations/businesses/'.$other->uuid.'/billing/invoices/'.$invoice->uuid)->assertNotFound();
});
it('does not back-charge historical orders or dispatch invoices for disabled policies', function () {
    Notification::fake();
    $order = billingOrder($this->business);
    OrderPlatformFee::where('order_id', $order->id)->delete();
    $order = $order->fresh();
    $order->update(['order_status_id' => billingStatus('Completed', 'order_statuses'), 'payment_status_id' => billingStatus('Completed', 'payment_statuses')]);
    expect(OrderPlatformFee::where('order_id', $order->id)->value('reason'))->toBe('legacy_not_billed');
    expect((float) OrderPlatformFee::where('order_id', $order->id)->value('amount'))->toBe(0.0);
    $this->artisan('billing:process')->assertSuccessful();
    Notification::assertNothingSent();
    expect(BusinessInvoice::count())->toBe(0);
});
it('allows payment and completion of existing orders while blocking staff order creation', function () {
    Notification::fake();
    $existing = billingOrder($this->business, 'Served');
    $invoice = billingInvoice($this);
    $this->service->dispatchInvoice($invoice);
    Carbon::setTestNow($invoice->fresh()->due_at);
    $method = PaymentMethod::create(['name' => 'Cash', 'code' => 'cash']);
    app(PaymentSettlementService::class)->recordManualPayment($existing, $method->id, 11800, 'EXISTING-ORDER', $this->operator->id);
    $existing->update(['order_status_id' => billingStatus('Completed', 'order_statuses')]);
    expect(OrderPlatformFee::where('order_id', $existing->id)->value('status'))->toBe('accrued');
    try {
        app(\App\Repositories\Order\OrderRepository::class)->storeForStaff($this->business, ['channel' => 'dine_in'], $this->operator->id);
        $this->fail('Staff order was allowed');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(423);
    }
});
it('allows reversal of an order after its invoice was already fully credited', function () {
    $invoice = billingInvoice($this);
    $this->service->recordReceipt($invoice->id, 'credit', 300, 'FULL-CREDIT', 'Approved correction', $this->operator->id);
    $fee = OrderPlatformFee::firstOrFail();
    $fee->order->update(['order_status_id' => billingStatus('Refunded', 'order_statuses')]);
    expect($fee->fresh()->status)->toBe('credited');
    expect((float) $invoice->fresh()->credit_amount)->toBe(300.0);
});
it('uses the commission agreement rate when payment configuration and billing schedules are saved', function () {
    billingReplaceRate($this, 2.75);
    $this->patchJson('/v1/operations/businesses/'.$this->business->uuid.'/payment-setting', [
        'provider' => 'manual', 'currency' => 'TZS',
        'commission_basis' => 'subtotal_excluding_tax', 'fee_bearer' => 'business',
        'settlement_mode' => 'manual_hold', 'settlement_requirement' => 'required',
        'is_checkout_enabled' => false, 'is_settlement_enabled' => false,
    ])->assertOk();
    $schedule = $this->payload;
    unset($schedule['commission_rate']);
    $this->patchJson($this->url, $schedule)->assertOk();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.commission_rate', '2.75')->assertJsonPath('data.effective_rate', 2.75);
    $order = billingOrder($this->business);
    expect((float) OrderPlatformFee::where('order_id', $order->id)->value('amount'))->toBe(275.0);
    $this->assertDatabaseHas('business_commission_policies', ['business_id' => $this->business->id, 'rate' => 2.75]);
});
