<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\BusinessStaffRole;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Services\OnboardingAgentService;
use Database\Seeders\OnboardingAgentRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(OnboardingAgentRoleSeeder::class);
    $this->agent = User::create(['name' => 'Agent', 'email' => 'staff-agent@example.test', 'password' => 'Example@2026', 'type' => 'agent', 'is_active' => true]);
    $this->agent->assignRole('onboarding-agent');
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->vendor = Vendor::create(['name' => 'Vendor', 'email' => 'staff-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Assigned restaurant', 'vendor_id' => $this->vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    $this->other = $this->business->replicate(['uuid']);
    $this->other->name = 'Unassigned restaurant';
    $this->other->code_prefix = Str::random(8);
    $this->other->order_prefix = Str::random(8);
    $this->other->save();
    $this->role = BusinessStaffRole::create(['name' => 'Waiter', 'slug' => 'waiter', 'is_active' => true]);
    $this->payload = ['name' => 'Test staff', 'business_staff_role_id' => $this->role->id, 'phone' => '255712345678', 'title' => 'Waiter'];
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'selected_businesses', [$this->business->id]);
    $this->url = '/v1/businesses/'.$this->business->uuid.'/staff';
    $this->actingAs($this->agent, 'api');
});
it('lets an assigned agent list add edit and reset staff using the existing workflow', function () {
    $this->getJson('/v1/business-staff-roles')->assertOk();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.staff.meta.total', 0);
    $this->postJson($this->url, $this->payload)->assertCreated();
    $staff = User::where('name', 'Test Staff')->firstOrFail();
    expect($staff->type)->toBe('business');
    expect($staff->must_change_password)->toBeTruthy();
    $this->getJson($this->url.'?search=Test&per_page=10')->assertOk()->assertJsonPath('data.staff.meta.total', 1);
    $this->putJson($this->url.'/'.$staff->uuid, ['name' => 'Updated staff', 'title' => 'Lead waiter', 'is_active' => false])->assertOk();
    expect($staff->fresh()->name)->toBe('Updated Staff');
    expect((bool) $staff->fresh()->is_active)->toBeFalse();
    $this->postJson($this->url.'/'.$staff->uuid.'/reset-password')->assertOk();
    expect(DB::table('onboarding_audits')->where('agent_id', $this->agent->id)->where('action', 'agent.request')->count())->toBeGreaterThanOrEqual(3);
});
it('supports a vendor-wide agent assignment for staff onboarding', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->postJson('/v1/businesses/'.$this->other->uuid.'/staff', $this->payload)->assertCreated();
});
it('denies staff operations for an unassigned business', function () {
    $url = '/v1/businesses/'.$this->other->uuid.'/staff';
    $this->getJson($url)->assertForbidden();
    $this->postJson($url, $this->payload)->assertForbidden();
    $this->postJson($this->url, $this->payload)->assertCreated();
    $staff = User::where('name', 'Test Staff')->firstOrFail();
    $this->putJson($url.'/'.$staff->uuid, ['name' => 'Forbidden'])->assertForbidden();
    $this->postJson($url.'/'.$staff->uuid.'/reset-password')->assertForbidden();
    expect($staff->fresh()->name)->toBe('Test Staff');
});
it('blocks staff access when the assignment or agent account is disabled', function () {
    DB::table('agent_vendor_assignments')->where('user_id', $this->agent->id)->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, $this->payload)->assertForbidden();
    DB::table('agent_vendor_assignments')->where('user_id', $this->agent->id)->update(['is_active' => true]);
    $this->agent->update(['is_active' => false]);
    $this->getJson($this->url)->assertForbidden();
});
it('keeps live orders reports operations and ownership administration out of agent access', function () {
    foreach (['orders', 'reports', 'dashboard'] as $path) {
        $this->getJson('/v1/businesses/'.$this->business->uuid.'/'.$path)->assertForbidden();
    }
    $this->getJson('/v1/operations/businesses/'.$this->business->uuid)->assertForbidden();
    $owner = User::create(['name' => 'Owner', 'email' => 'staff-test-owner@example.test', 'password' => 'Example@2026', 'type' => 'owner', 'is_active' => true]);
    $owner->businesses()->attach($this->business->id, ['business_role' => 'vendor_manager', 'title' => 'Owner', 'is_active' => true]);
    $this->putJson($this->url.'/'.$owner->uuid, ['is_active' => false])->assertForbidden();
    expect((bool) $owner->fresh()->is_active)->toBeTrue();
});
