<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Notifications\OnboardingAgentInvitation;
use App\Services\OnboardingAgentService;
use Database\Seeders\OnboardingAgentRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Notification::fake();
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'api']);
    $this->seed(OnboardingAgentRoleSeeder::class);
    $this->admin = User::create(['name' => 'Admin', 'email' => 'ops@example.test', 'type' => 'paperstic', 'password' => 'Example-password-2026', 'is_active' => true, 'email_verified_at' => now()]);
    $this->admin->assignRole('super-admin');
    $this->agent = User::create(['name' => 'Agent', 'email' => 'agent@gmail.com', 'type' => 'agent', 'password' => 'Example-password-2026', 'is_active' => true, 'email_verified_at' => now()]);
    $this->agent->assignRole('onboarding-agent');
    $this->country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $city = \App\Models\Location\City::create(['country_id' => $this->country->id, 'name' => 'Dar es Salaam']);
    \App\Models\Location\District::create(['city_id' => $city->id, 'name' => 'Kinondoni']);
    DB::table('business_types')->insert(['name' => 'Restaurant', 'created_at' => now(), 'updated_at' => now()]);
    $this->vendor = Vendor::create(['country_id' => $this->country->id, 'name' => 'Assigned vendor', 'email' => 'vendor@example.test']);
    $this->otherVendor = Vendor::create(['country_id' => $this->country->id, 'name' => 'Other vendor', 'email' => 'other@example.test']);
});
function imageUploadBusiness(Vendor $vendor, string $name): Business
{
    return Business::create(['vendor_id' => $vendor->id, 'district_id' => 1, 'business_type_id' => 1, 'name' => $name, 'location' => 'Dar', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
}

it('lets an owner upload and replace the discovery photo without changing the logo', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $owner = User::create(['name' => 'Owner', 'email' => 'photo-owner@example.test', 'type' => 'owner', 'password' => 'Example@2026', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->vendor->users()->attach($owner->id, ['is_primary' => true, 'is_active' => true]);
    $business = imageUploadBusiness($this->vendor, 'Venue photo');
    $business->update(['is_active' => true, 'latitude' => -6.8, 'longitude' => 39.2, 'logo_path' => 'logos/original.png']);
    $url = '/v1/businesses/'.$business->uuid.'/discovery-image';
    $photo = fn () => \Illuminate\Http\UploadedFile::fake()->image('venue.jpg', 1200, 675);
    $first = $this->actingAs($owner, 'api')->postJson($url, ['image' => $photo()])->assertOk()->json('data.business.image_url');
    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    $firstPath = 'business-discovery/'.$business->uuid.'/'.basename($first);
    $disk->assertExists($firstPath);
    $this->getJson('/v1/businesses/'.$business->uuid)->assertOk()->assertJsonPath('data.business.image_url', $first);
    $this->getJson('/v1/discover/businesses')->assertOk()->assertJsonPath('data.businesses.0.image', $first);
    $next = $this->postJson($url, ['image' => $photo()])->assertOk()->json('data.business.image_url');
    expect($next)->not->toBe($first);
    $disk->assertMissing($firstPath);
    $disk->assertExists('business-discovery/'.$business->uuid.'/'.basename($next));
    expect($business->fresh()->logo_path)->toBe('logos/original.png');
});

it('allows assigned onboarding agents and denies access to other businesses', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $allowed = imageUploadBusiness($this->vendor, 'Allowed photo');
    $other = imageUploadBusiness($this->vendor, 'Other photo');
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'selected_businesses', [$allowed->id]);
    $this->actingAs($this->agent, 'api')->postJson('/v1/businesses/'.$allowed->uuid.'/discovery-image', ['image' => \Illuminate\Http\UploadedFile::fake()->image('photo.png', 600, 300)])->assertOk();
    $this->postJson('/v1/businesses/'.$other->uuid.'/discovery-image', ['image' => \Illuminate\Http\UploadedFile::fake()->image('photo.png', 600, 300)])->assertForbidden();
    expect($other->fresh()->image_url)->toBeNull();
});

it('rejects unsuitable photos and preserves the previous image', function (string $kind) {
    \Illuminate\Support\Facades\Storage::fake('public');
    $business = imageUploadBusiness($this->vendor, 'Validation photo');
    $business->update(['image_url' => 'https://example.test/existing.jpg']);
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $file = match ($kind) {
        'small' => \Illuminate\Http\UploadedFile::fake()->image('small.jpg', 100, 100),
        'large' => \Illuminate\Http\UploadedFile::fake()->image('large.jpg', 1200, 675)->size(5121),
        default => \Illuminate\Http\UploadedFile::fake()->create('file.pdf', 20, 'application/pdf'),
    };
    $this->actingAs($this->agent, 'api')->postJson('/v1/businesses/'.$business->uuid.'/discovery-image', ['image' => $file])->assertUnprocessable()->assertJsonValidationErrors('image');
    expect($business->fresh()->image_url)->toBe('https://example.test/existing.jpg');
    expect(\Illuminate\Support\Facades\Storage::disk('public')->allFiles())->toBe([]);
})->with(['small', 'large', 'invalid']);

it('saves and clears discovery descriptions across Business, Discover and Operations', function () {
    $business = imageUploadBusiness($this->vendor, 'Description business');
    $business->update(['is_active' => true, 'latitude' => -6.8, 'longitude' => 39.2]);
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $url = '/v1/businesses/'.$business->uuid.'/discovery-profile';
    $this->actingAs($this->agent, 'api')->patchJson($url, ['discovery_description' => '  Fresh local food by the sea.  '])->assertOk()->assertJsonPath('data.business.discovery_description', 'Fresh local food by the sea.');
    $this->getJson('/v1/businesses/'.$business->uuid)->assertOk()->assertJsonPath('data.business.discovery_description', 'Fresh local food by the sea.');
    $this->getJson('/v1/discover/businesses')->assertOk()->assertJsonPath('data.businesses.0.short_description', 'Fresh local food by the sea.');
    \Spatie\Permission\Models\Permission::findOrCreate('operations.businesses.view', 'api');
    $this->admin->givePermissionTo('operations.businesses.view');
    $this->actingAs($this->admin, 'api')->getJson('/v1/operations/businesses/'.$business->uuid)->assertOk()->assertJsonPath('data.business.discovery_description', 'Fresh local food by the sea.');
    $this->actingAs($this->agent, 'api')->patchJson($url, ['discovery_description' => '  '])->assertOk()->assertJsonPath('data.business.discovery_description', null);
    expect($business->fresh()->discovery_description)->toBeNull();
});

it('validates descriptions and limits updates to assigned businesses', function () {
    $one = imageUploadBusiness($this->vendor, 'Assigned description');
    $other = imageUploadBusiness($this->vendor, 'Unassigned description');
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'selected_businesses', [$one->id]);
    $this->actingAs($this->agent, 'api');
    $url = '/v1/businesses/'.$one->uuid.'/discovery-profile';
    foreach ([[], ['discovery_description' => str_repeat('a', 2001)], ['discovery_description' => ['invalid']]] as $payload) {
        $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('discovery_description');
    }
    $this->patchJson('/v1/businesses/'.$other->uuid.'/discovery-profile', ['discovery_description' => 'Not allowed'])->assertForbidden();
    expect($other->fresh()->discovery_description)->toBeNull();
});
