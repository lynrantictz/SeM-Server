<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\Business\VendorInvitation;
use App\Models\Location\Country;
use App\Services\OnboardingAgentService;
use Database\Seeders\OnboardingAgentRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Notification::fake();
    Role::findOrCreate('owner', 'api');
    $this->seed(OnboardingAgentRoleSeeder::class);
    $this->agent = User::create(['name' => 'Agent', 'email' => 'visibility-agent@example.test', 'password' => 'Example@2026', 'type' => 'agent', 'is_active' => true, 'email_verified_at' => now()]);
    $this->agent->assignRole('onboarding-agent');
    $this->operator = User::create(['name' => 'Operator', 'email' => 'visibility-ops@example.test', 'password' => 'Example@2026', 'type' => 'paperstic', 'is_active' => true]);
    foreach (['operations.vendors.view', 'operations.businesses.view'] as $permission) {
        Permission::findOrCreate($permission, 'api');
        $this->operator->givePermissionTo($permission);
    }
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['name' => 'Dar', 'country_id' => $country->id]);
    $district = \App\Models\Location\District::create(['name' => 'Central', 'city_id' => $city->id]);
    DB::table('business_types')->insert(['name' => 'Restaurant']);
    $this->vendor = Vendor::create(['name' => 'Vendor', 'email' => 'visibility-vendor@example.test', 'country_id' => $country->id]);
    $this->business = Business::create(['name' => 'Business', 'vendor_id' => $this->vendor->id, 'district_id' => $district->id, 'business_type_id' => 1, 'location' => 'Dar', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->inviteUrl = '/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/owner';
    $this->actingAs($this->agent, 'api');
});
function assertOwnerVisible($test, string $status, ?string $email = 'invited-owner@example.test'): void
{
    $test->actingAs($test->agent, 'api');
    foreach ([['/v1/onboarding-agent', 'data.vendors.0.owner_summary'], ['/v1/vendors/'.$test->vendor->uuid, 'data.vendor.owner_summary'], ['/v1/businesses/'.$test->business->uuid, 'data.business.owner_summary']] as [$url, $path]) {
        $test->getJson($url)->assertOk()->assertJsonPath($path.'.status', $status)->assertJsonPath($path.'.email', $email)->assertJsonMissingPath($path.'.token');
    }
    $test->actingAs($test->operator, 'api');
    foreach ([['/v1/operations/vendors/'.$test->vendor->uuid, 'data.vendor.owner_summary'], ['/v1/operations/businesses/'.$test->business->uuid, 'data.business.owner_summary']] as [$url, $path]) {
        $test->getJson($url)->assertOk()->assertJsonPath($path.'.status', $status)->assertJsonPath($path.'.email', $email)->assertJsonMissingPath($path.'.token');
    }
}
it('shows no owner before an invitation is created', function () {
    assertOwnerVisible($this, 'not_invited', null);
});
it('shows the invited identity before acceptance on every profile', function () {
    $this->postJson($this->inviteUrl, ['name' => 'Invited owner', 'email' => 'invited-owner@example.test'])->assertOk();
    assertOwnerVisible($this, 'pending');
    expect($this->vendor->users()->wherePivot('is_primary', true)->exists())->toBeFalse();
});
it('shows expiry and refreshes the invitation information after a resend', function () {
    $this->postJson($this->inviteUrl, ['name' => 'Invited owner', 'email' => 'invited-owner@example.test'])->assertOk();
    $invitation = VendorInvitation::firstOrFail();
    $oldToken = $invitation->token;
    $invitation->update(['expires_at' => now()->subMinute()]);
    assertOwnerVisible($this, 'expired');
    $this->actingAs($this->agent, 'api')->postJson($this->inviteUrl, ['name' => 'Invited owner', 'email' => 'invited-owner@example.test'])->assertOk();
    expect(VendorInvitation::count())->toBe(1);
    expect(VendorInvitation::first()->token)->not->toBe($oldToken);
    assertOwnerVisible($this, 'pending');
});
it('shows accepted ownership and later disabled access without reverting to not invited', function () {
    $this->postJson($this->inviteUrl, ['name' => 'Invited owner', 'email' => 'invited-owner@example.test'])->assertOk();
    $invitation = VendorInvitation::with('user')->firstOrFail();
    $invitation->update(['accepted_at' => now()]);
    $invitation->user->update(['is_active' => true, 'email_verified_at' => now()]);
    $this->vendor->users()->updateExistingPivot($invitation->user_id, ['is_primary' => true, 'is_active' => true, 'accepted_at' => now()]);
    assertOwnerVisible($this, 'accepted');
    $invitation->user->update(['is_active' => false]);
    assertOwnerVisible($this, 'disabled');
});
it('keeps owner identity behind the existing assignment and operations permissions', function () {
    $this->postJson($this->inviteUrl, ['name' => 'Invited owner', 'email' => 'invited-owner@example.test'])->assertOk();
    DB::table('agent_vendor_assignments')->where('user_id', $this->agent->id)->update(['is_active' => false]);
    $this->getJson('/v1/vendors/'.$this->vendor->uuid)->assertForbidden();
    $this->getJson('/v1/businesses/'.$this->business->uuid)->assertForbidden();
    $this->operator->syncPermissions([]);
    $this->actingAs($this->operator->fresh(), 'api')->getJson('/v1/operations/vendors/'.$this->vendor->uuid)->assertForbidden();
    $this->getJson('/v1/operations/businesses/'.$this->business->uuid)->assertForbidden();
});

it('logs fresh owner acceptance links immediately on local sends and resends', function () {
    $logger = Mockery::mock(\Psr\Log\LoggerInterface::class);
    \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('single')->twice()->andReturn($logger);
    $links = [];
    $logger->shouldReceive('info')->twice()->withArgs(function ($message, $context) use (&$links) {
        expect($message)->toBe('Local owner invitation link');
        expect($context['owner_email'])->toBe('invited-owner@example.test');
        expect($context['vendor_uuid'])->toBe($this->vendor->uuid);
        $links[] = $context['accept_url'];

        return true;
    });
    $this->app['env'] = 'local';
    try {
        foreach ([1, 2] as $attempt) {
            $this->postJson($this->inviteUrl, ['name' => 'Invited owner', 'email' => 'invited-owner@example.test'])->assertOk();
            $url = $links[$attempt - 1];
            expect($url)->toStartWith(rtrim(config('app.business_url'), '/').'/accept-vendor-invitation?token=');
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            expect(VendorInvitation::firstOrFail()->token)->toBe(hash('sha256', $query['token']));
        }
        expect($links[0])->not->toBe($links[1]);
    } finally {
        $this->app['env'] = 'testing';
    }
});
it('never logs owner activation tokens outside local development', function () {
    \Illuminate\Support\Facades\Log::shouldReceive('channel')->never();
    $this->app['env'] = 'production';
    try {
        $this->postJson($this->inviteUrl, ['name' => 'Invited owner', 'email' => 'invited-owner@example.test'])->assertOk();
    } finally {
        $this->app['env'] = 'testing';
    }
});
