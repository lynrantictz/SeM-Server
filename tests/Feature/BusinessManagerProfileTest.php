<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->vendor = Vendor::create(['name' => 'Vendor', 'email' => 'manager-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Restaurant', 'tin' => '123-456', 'vendor_id' => $this->vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->other = $this->business->replicate(['uuid']);
    $this->other->tin = '654-321';
    $this->other->order_prefix = Str::random(8);
    $this->other->code_prefix = Str::random(8);
    $this->other->save();
    $this->manager = User::create(['name' => 'Business Manager', 'email' => 'profile-manager@example.test', 'password' => 'Example@2026', 'type' => 'business', 'is_active' => true]);
    $this->manager->businesses()->attach($this->business->id, ['business_role' => 'business_manager', 'title' => 'Manager', 'is_active' => true]);
    $this->url = '/v1/businesses/'.$this->business->uuid;
    $this->payload = ['name' => $this->business->name, 'tin' => $this->business->tin, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Updated address', 'latitude' => -6.8, 'longitude' => 39.2, 'contacts' => [['contact' => '255712345678']]];
    $this->actingAs($this->manager, 'api');
});
it('gives the assigned business manager operational profile access without registration authority', function () {
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.business.profile_permissions.can_update_profile', true)->assertJsonPath('data.business.profile_permissions.can_manage_operations', true)->assertJsonPath('data.business.profile_permissions.can_manage_staff', true)->assertJsonPath('data.business.profile_permissions.can_manage_registration', false);
    $this->getJson('/v1/businesses')->assertForbidden();
    $this->getJson('/v1/vendors')->assertForbidden();
    $this->getJson($this->url.'/menu-settings')->assertOk();
    $this->getJson($this->url.'/documents')->assertOk()->assertJsonPath('data.can_upload', false);
});
it('lets a manager update location contacts description logo and discovery image', function () {
    Storage::fake('public');
    $this->putJson($this->url, $this->payload)->assertOk()->assertJsonPath('data.business.location', 'Updated address')->assertJsonPath('data.business.contacts.0.contact', '255712345678');
    $this->patchJson($this->url.'/discovery-profile', ['discovery_description' => 'Fresh local dishes'])->assertOk();
    $this->postJson($this->url.'/logo', ['logo' => UploadedFile::fake()->image('logo.png', 512, 512)])->assertOk();
    $this->postJson($this->url.'/discovery-image', ['image' => UploadedFile::fake()->image('venue.jpg', 600, 300)])->assertOk();
    expect($this->business->fresh()->name)->toBe('Restaurant');
    expect($this->business->fresh()->tin)->toBe('123-456');
});
it('rejects changes to registration activation tax and ownership fields', function (array $change) {
    $this->putJson($this->url, [...$this->payload, ...$change])->assertForbidden();
    expect($this->business->fresh()->location)->toBe('Dar');
})->with([[['name' => 'New legal name']], [['tin' => '999-999']], [['is_active' => true]], [['vendor_id' => 999]], [['tax_allowed' => true]], [['tax_status' => 'vat_registered']], [['code_prefix' => 'HACK']], [['commission_rate' => 0]]]);
it('does not allow staff to register new businesses or vendors', function () {
    $payload = [...$this->payload, 'name' => 'New business', 'tin' => 'NEW-TIN'];
    $this->postJson('/v1/businesses/'.$this->vendor->uuid, $payload)->assertForbidden();
    $this->postJson('/v1/vendors', ['name' => 'New vendor', 'tin' => 'NEW-VENDOR-TIN', 'country_id' => $this->vendor->country_id, 'email' => 'new-vendor@example.test', 'phone' => '255713123456', 'address' => 'Dar'])->assertForbidden();
    expect(Business::count())->toBe(2);
    expect(Vendor::count())->toBe(1);
});
it('denies other businesses and non-manager staff even if the manager has a generic permission', function () {
    $other = '/v1/businesses/'.$this->other->uuid;
    $this->getJson($other)->assertNotFound();
    $this->putJson($other, [...$this->payload, 'tin' => $this->other->tin])->assertForbidden();
    $this->patchJson($other.'/discovery-profile', ['discovery_description' => 'Forbidden'])->assertForbidden();
    Permission::findOrCreate('business.edit', 'api');
    $this->manager->givePermissionTo('business.edit');
    $this->manager->businesses()->updateExistingPivot($this->business->id, ['business_role' => 'waiter']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.business.profile_permissions.can_update_profile', false);
    $this->putJson($this->url, $this->payload)->assertForbidden();
    $this->patchJson($this->url.'/discovery-profile', ['discovery_description' => 'Forbidden'])->assertForbidden();
});
it('keeps payment editing governed by the existing payment permissions', function () {
    $this->getJson($this->url.'/payment-methods')->assertOk()->assertJsonPath('data.permissions.can_update', false);
    Permission::findOrCreate('business.payment_methods.update', 'api');
    $this->manager->givePermissionTo('business.payment_methods.update');
    $this->getJson($this->url.'/payment-methods')->assertOk()->assertJsonPath('data.permissions.can_update', true);
});
it('revokes profile editing when the manager membership or user is disabled', function () {
    $this->manager->businesses()->updateExistingPivot($this->business->id, ['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, $this->payload)->assertForbidden();
    $this->manager->businesses()->updateExistingPivot($this->business->id, ['is_active' => true]);
    $this->manager->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    $this->putJson($this->url, $this->payload)->assertForbidden();
});
