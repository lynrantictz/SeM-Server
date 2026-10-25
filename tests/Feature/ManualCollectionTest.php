<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\OnboardingPackage;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    foreach (['operations.businesses.view', 'operations.businesses.manage', 'operations.settlements.manage'] as $permission) {
        Permission::findOrCreate($permission, 'api');
    }
    $this->operator = User::create(['name' => 'Operations', 'email' => 'payment-ops@example.test', 'password' => 'Example@2026', 'type' => 'paperstic', 'is_active' => true]);
    $this->operator->givePermissionTo(['operations.businesses.view', 'operations.businesses.manage', 'operations.settlements.manage']);
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $vendor = Vendor::create(['name' => 'Vendor', 'email' => 'onboarding-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Business', 'vendor_id' => $vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->package = OnboardingPackage::create(['key' => 'basic', 'name' => 'Basic', 'price' => 100, 'currency' => 'TZS', 'is_active' => true]);
    $this->url = '/v1/operations/businesses/'.$this->business->uuid.'/onboarding-payment';
    $this->payload = ['package_id' => $this->package->id, 'amount_due' => 100, 'amount_paid' => 0, 'currency' => 'TZS', 'status' => 'pending', 'payment_reference' => 'RECEIPT-1', 'payment_method' => 'Cash', 'notes' => 'Initial onboarding'];
    $this->actingAs($this->operator, 'api');
    Storage::fake('local');
});


it('saves direct payment configuration and rejects gateway activation', function () {
    $url = '/v1/operations/businesses/'.$this->business->uuid.'/payment-setting';
    $payload = ['provider' => 'manual', 'currency' => 'TZS', 'commission_basis' => 'subtotal_excluding_tax', 'fee_bearer' => 'business', 'settlement_mode' => 'manual_hold', 'settlement_requirement' => 'not_required', 'is_checkout_enabled' => false, 'is_settlement_enabled' => false];
    $this->patchJson($url, $payload)->assertOk()->assertJsonPath('data.payment_setting.provider', 'manual');
    foreach (['provider' => 'azampay', 'is_checkout_enabled' => true, 'is_settlement_enabled' => true] as $field => $value) {
        $this->patchJson($url, [...$payload, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->operator->revokePermissionTo('operations.settlements.manage');
    $this->actingAs($this->operator->fresh(), 'api')->patchJson($url, $payload)->assertForbidden();
});

it('records manual receipt and fee allocation without creating a payout', function () {
    $method = \App\Models\Payment\PaymentMethod::create(['name' => 'Cash', 'code' => 'cash']);
    DB::table('payment_statuses')->insert(['name' => 'Completed']);
    $order = \App\Models\Order\Order::create(['business_id' => $this->business->id, 'number' => 'MANUAL-1', 'total_items_amount' => 100, 'total_amount' => 100, 'due_amount' => 100]);
    $payment = app(\App\Services\PaymentGateway\PaymentSettlementService::class)->recordManualPayment($order, $method->id, 100, 'REF-1', $this->operator->id);
    expect($payment->status)->toBe('SUCCESS');
    expect($payment->metadata['mode'])->toBe('manual');
    expect((float) $order->fresh()->due_amount)->toBe(0.0);
    expect($order->fresh()->payment_status_id)->toBe(\App\Models\Payment\PaymentStatus::where('name', 'Completed')->value('id'));
    expect($this->business->paymentSetting()->first()->provider)->toBe('manual');
    $this->assertDatabaseCount('payment_allocations', 1);
    $this->assertDatabaseCount('business_payouts', 0);
});

it('blocks retained gateway and sandbox routes without creating payments', function (string $path) {
    $this->postJson('/v1/'.$path, ['phone' => '255758483019', 'provider' => 'Mpesa'])->assertUnprocessable();
    $this->assertDatabaseCount('payments', 0);
})->with(['payment-gateways/UNKNOWN/mno-checkout', 'payment-gateways/UNKNOWN/complete-sandbox-demo', 'payments/azampay/callback']);

it('does not execute an old queued gateway request', function () {
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    app(\App\Services\PaymentGateway\PaymentCheckoutService::class)->sendQueuedMnoCheckout(999);
    \Illuminate\Support\Facades\Http::assertNothingSent();
    $this->assertDatabaseCount('payments', 0);
});

function approveActivationMethods(Business $business): void
{
    foreach (['mobile_money', 'bank_transfer', 'cash', 'pos_card'] as $code) {
        $method = \App\Models\Payment\PaymentMethod::create(['name' => $code, 'code' => $code, 'requires_identifier' => $code !== 'cash', 'is_active' => true]);
        $configuration = $business->paymentMethods()->create(['payment_method_id' => $method->id, 'is_enabled' => true, 'status' => 'active']);
        if ($code !== 'cash') {
            $configuration->accounts()->create(['account_number' => '123456', 'account_holder_name' => 'Business', 'bank_name' => $code === 'bank_transfer' ? 'Bank' : null, 'currency' => 'TZS', 'is_enabled' => true, 'status' => 'active']);
        }
    }
}

it('keeps approved methods checked before activation and enables manual collection only after activation', function () {
    foreach (['operations.businesses.approve', 'operations.businesses.suspend'] as $permission) {
        Permission::findOrCreate($permission, 'api');
        $this->operator->givePermissionTo($permission);
    }
    approveActivationMethods($this->business);
    $status = app(\App\Services\Business\BusinessActivationService::class)->status($this->business->fresh());
    expect($status['business_enabled'])->toBeFalse();
    expect($status['payment_methods_approved'])->toBeTrue();
    expect($status['activation_eligible'])->toBeTrue();
    expect($status['manual_payment_ready'])->toBeFalse();
    $url = '/v1/operations/businesses/'.$this->business->uuid.'/status';
    $this->patchJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.activation.manual_payment_ready', true)->assertJsonPath('data.activation.business_enabled', true);
    $this->patchJson($url, ['is_active' => false])->assertOk()->assertJsonPath('data.activation.manual_payment_ready', false)->assertJsonPath('data.activation.payment_methods_approved', true);
});

it('rejects activation when requirements are incomplete and leaves the business inactive', function (string $blocker) {
    Permission::findOrCreate('operations.businesses.approve', 'api');
    $this->operator->givePermissionTo('operations.businesses.approve');
    if ($blocker !== 'no_methods') approveActivationMethods($this->business);
    if ($blocker === 'pending_method') $this->business->paymentMethods()->first()->update(['status' => 'pending']);
    if ($blocker === 'incomplete_account') $this->business->paymentMethods()->first()->accounts()->update(['account_number' => '']);
    if ($blocker === 'missing_document') {
        $type = \App\Models\Business\ComplianceDocumentType::create(['key' => 'required_test', 'name' => 'Required test', 'scope' => 'business', 'is_active' => true]);
        \App\Models\Business\ComplianceDocumentTypeRule::create(['compliance_document_type_id' => $type->id, 'required_for_payment_activation' => true, 'is_active' => true]);
    }
    $this->patchJson('/v1/operations/businesses/'.$this->business->uuid.'/status', ['is_active' => true])->assertUnprocessable();
    expect((bool) $this->business->fresh()->is_active)->toBeFalse();
})->with(['no_methods', 'pending_method', 'incomplete_account', 'missing_document']);

it('requires activation permission even with approved payments', function () {
    approveActivationMethods($this->business);
    $this->patchJson('/v1/operations/businesses/'.$this->business->uuid.'/status', ['is_active' => true])->assertForbidden();
    expect((bool) $this->business->fresh()->is_active)->toBeFalse();
});
