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
    foreach (['operations.businesses.view', 'operations.businesses.manage'] as $permission) {
        Permission::findOrCreate($permission, 'api');
    }
    $this->operator = User::create(['name' => 'Operations', 'email' => 'payment-ops@example.test', 'password' => 'Example@2026', 'type' => 'paperstic', 'is_active' => true]);
    $this->operator->givePermissionTo(['operations.businesses.view', 'operations.businesses.manage']);
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

it('saves every supported onboarding status and records payment and verification times', function (string $status, int $paid) {
    $response = $this->patchJson($this->url, [...$this->payload, 'status' => $status, 'amount_paid' => $paid])->assertOk()->assertJsonPath('data.onboarding_payment.status', $status)->assertJsonPath('data.onboarding_payment.payment_reference', 'RECEIPT-1');
    $payment = $this->business->onboardingPayment()->firstOrFail();
    expect((bool) $payment->verified_at)->toBe(in_array($status, ['paid', 'waived'], true));
    expect((bool) $payment->paid_at)->toBe($status === 'paid');
    expect($payment->payment_method)->toBe('Cash');
    expect($payment->notes)->toBe('Initial onboarding');
})->with([['pending', 0], ['submitted', 0], ['partially_paid', 40], ['paid', 100], ['waived', 0], ['refunded', 100]]);

it('saves the selected package and currency and updates one record on later edits', function () {
    $first = $this->patchJson($this->url, $this->payload)->assertOk()->json('data.onboarding_payment.uuid');
    $other = OnboardingPackage::create(['key' => 'premium', 'name' => 'Premium', 'price' => 25, 'currency' => 'USD', 'is_active' => true]);
    $this->patchJson($this->url, [...$this->payload, 'package_id' => $other->id, 'currency' => 'USD', 'amount_due' => 25, 'status' => 'paid', 'amount_paid' => 25])->assertOk()->assertJsonPath('data.onboarding_payment.uuid', $first)->assertJsonPath('data.onboarding_payment.package_id', $other->id)->assertJsonPath('data.onboarding_payment.package', 'Premium')->assertJsonPath('data.onboarding_payment.currency', 'USD');
    expect($this->business->onboardingPayment()->count())->toBe(1);
});

it('rejects inconsistent amounts, currency and unavailable packages', function (array $changes, string $field) {
    $this->patchJson($this->url, [...$this->payload, ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([[['status' => 'paid', 'amount_paid' => 50], 'amount_paid'], [['status' => 'partially_paid', 'amount_paid' => 0], 'amount_paid'], [['status' => 'partially_paid', 'amount_paid' => 100], 'amount_paid'], [['amount_due' => -1], 'amount_due'], [['amount_paid' => -1], 'amount_paid'], [['currency' => 'USD'], 'currency'], [['package_id' => 9999], 'package_id']]);

it('allows zero-value waivers and corrections to an existing inactive-package record', function () {
    $this->patchJson($this->url, [...$this->payload, 'amount_due' => 0, 'status' => 'waived'])->assertOk();
    $this->package->update(['is_active' => false]);
    $this->patchJson($this->url, [...$this->payload, 'status' => 'paid', 'amount_paid' => 100])->assertOk();
    $this->business->onboardingPayment()->delete();
    $this->patchJson($this->url, $this->payload)->assertUnprocessable()->assertJsonValidationErrors('package_id');
});

it('uploads and replaces private PDF proof without overwriting the recorded payment status', function (string $status, int $paid, string $expectedStatus) {
    $this->patchJson($this->url, [...$this->payload, 'status' => $status, 'amount_paid' => $paid])->assertOk();
    $this->post($this->url.'/proof', ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.onboarding_payment.status', $expectedStatus);
    $oldPath = $this->business->onboardingPayment()->first()->proof_path;
    Storage::disk('local')->assertExists($oldPath);
    $this->get($this->url.'/proof')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->post($this->url.'/proof', ['file' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.onboarding_payment.proof_filename', 'replacement.pdf');
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($this->business->onboardingPayment()->first()->proof_path);
})->with([['pending', 0, 'submitted'], ['partially_paid', 40, 'partially_paid'], ['paid', 100, 'paid'], ['waived', 0, 'waived'], ['refunded', 100, 'refunded']]);

it('rejects invalid proofs without replacing an existing file and requires a saved record', function () {
    $this->post($this->url.'/proof', ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertNotFound();
    $this->patchJson($this->url, $this->payload)->assertOk();
    $this->post($this->url.'/proof', ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
    $path = $this->business->onboardingPayment()->first()->proof_path;
    $this->post($this->url.'/proof', ['file' => UploadedFile::fake()->image('wrong.png')], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post($this->url.'/proof', ['file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
    Storage::disk('local')->assertExists($path);
});

it('blocks saving and proof upload without business management permission', function () {
    $this->operator->revokePermissionTo('operations.businesses.manage');
    $this->actingAs($this->operator->fresh(), 'api')->patchJson($this->url, $this->payload)->assertForbidden();
    $this->post($this->url.'/proof', ['file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();
});
