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
function onboardingBusiness(Vendor $vendor, string $name): Business
{
    return Business::create(['vendor_id' => $vendor->id, 'district_id' => 1, 'business_type_id' => 1, 'name' => $name, 'location' => 'Dar', 'order_prefix' => Str::random(8), 'code_prefix' => Str::random(8)]);
}
it('lets Operations create an agent with a Gmail and a hashed single-use invitation', function () {
    $this->actingAs($this->admin, 'api')->postJson('/v1/operations/onboarding-agents', ['name' => 'New Agent', 'email' => 'newagent@gmail.com'])->assertCreated()->assertJsonPath('data.agent.accepted', false);
    $agent = User::where('email', 'newagent@gmail.com')->firstOrFail();
    expect($agent->type)->toBe('agent');
    expect($agent->hasRole('onboarding-agent'))->toBeTrue();
    expect($agent->getAllPermissions()->pluck('name')->filter(fn ($p) => str_starts_with($p, 'operations.')))->toHaveCount(0);
    $token = null;
    Notification::assertSentTo($agent, OnboardingAgentInvitation::class, function ($n) use (&$token) {
        parse_str(parse_url($n->url, PHP_URL_QUERY), $query);
        $token = $query['token'];

        return true;
    });
    expect(DB::table('agent_invitations')->where('user_id', $agent->id)->value('token_hash'))->toBe(hash('sha256', $token));
    $this->postJson('/v1/agent-invitations/accept', ['token' => $token, 'password' => 'A-new-test-password-2026', 'password_confirmation' => 'A-new-test-password-2026'])->assertOk();
    expect(Hash::check('A-new-test-password-2026', $agent->fresh()->password))->toBeTrue();
    $this->postJson('/v1/agent-invitations/accept', ['token' => $token, 'password' => 'Another-password-2026', 'password_confirmation' => 'Another-password-2026'])->assertUnprocessable();
});
it('rejects agent login at Operations but allows Business login', function () {
    $this->postJson('/v1/operations/login', ['login' => $this->agent->email, 'password' => 'Example-password-2026'])->assertJsonPath('success', false);
    $this->postJson('/v1/login', ['login' => $this->agent->email, 'password' => 'Example-password-2026'])->assertJsonPath('success', true);
});
it('denies Operations requests even if an agent accidentally receives Operations permissions', function () {
    $this->agent->assignRole('super-admin');
    $this->actingAs($this->agent, 'api')->getJson('/v1/operations/onboarding-agents')->assertForbidden();
    $this->getJson('/v1/operations/dashboard')->assertForbidden();
});
it('creates a vendor without making the agent its owner', function () {
    $this->actingAs($this->agent, 'api')->postJson('/v1/vendors', ['country_id' => $this->country->id, 'tin' => '123456789', 'name' => 'Agent-created vendor', 'email' => 'created@example.test', 'phone' => '0712345678', 'address' => 'Dar'])->assertCreated();
    $v = Vendor::where('email', 'created@example.test')->firstOrFail();
    expect($v->users()->wherePivot('is_primary', true)->count())->toBe(0);
    expect((bool) $this->agent->vendors()->whereKey($v->id)->first()->pivot->is_primary)->toBeFalse();
    expect(DB::table('agent_vendor_assignments')->where('vendor_id', $v->id)->exists())->toBeTrue();
});
it('limits profiles and direct requests to selected businesses', function () {
    $one = onboardingBusiness($this->vendor, 'Allowed');
    $two = onboardingBusiness($this->vendor, 'Sibling');
    $this->actingAs($this->admin, 'api')->postJson('/v1/operations/onboarding-agents/'.$this->agent->uuid.'/assignments', ['vendor_uuid' => $this->vendor->uuid, 'scope' => 'selected_businesses', 'business_uuids' => [$one->uuid]])->assertOk();
    $this->actingAs($this->agent, 'api')->getJson('/v1/me')->assertOk()->assertJsonCount(1, 'data.user.vendors.0.businesses')->assertJsonPath('data.user.vendors.0.businesses.0.uuid', $one->uuid);
    $this->getJson('/v1/businesses/'.$two->uuid.'/menu-settings')->assertForbidden();
    $this->getJson('/v1/vendors/'.$this->otherVendor->uuid)->assertForbidden();
    $this->getJson('/v1/onboarding-agent')->assertOk()->assertJsonCount(1, 'data.vendors.0.businesses');
});
it('rejects assignment of businesses from a different vendor', function () {
    $business = onboardingBusiness($this->otherVendor, 'Wrong vendor');
    $this->actingAs($this->admin, 'api')->postJson('/v1/operations/onboarding-agents/'.$this->agent->uuid.'/assignments', ['vendor_uuid' => $this->vendor->uuid, 'scope' => 'selected_businesses', 'business_uuids' => [$business->uuid]])->assertUnprocessable();
});
it('removes assignment access immediately', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->admin, 'api')->deleteJson('/v1/operations/onboarding-agents/'.$this->agent->uuid.'/assignments/'.$this->vendor->uuid)->assertOk();
    $this->actingAs($this->agent, 'api')->getJson('/v1/vendors/'.$this->vendor->uuid)->assertForbidden();
});
it('revokes tokens and blocks existing sessions when an agent is disabled', function () {
    $this->agent->createToken('test');
    $this->actingAs($this->admin, 'api')->patchJson('/v1/operations/onboarding-agents/'.$this->agent->uuid.'/status', ['active' => false])->assertOk();
    expect($this->agent->tokens()->count())->toBe(0);
    $this->actingAs($this->agent->fresh(), 'api')->getJson('/v1/onboarding-agent')->assertForbidden();
});
it('does not allow an agent to assign itself or approve onboarding', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->agent, 'api')->postJson('/v1/operations/onboarding-agents/'.$this->agent->uuid.'/assignments', ['vendor_uuid' => $this->otherVendor->uuid, 'scope' => 'all_businesses'])->assertForbidden();
    $this->patchJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/progress', ['stage' => 'ready'])->assertForbidden();
    $this->patchJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/progress', ['stage' => 'submitted', 'notes' => 'Ready for review'])->assertOk();
});
it('invites an owner without attaching agent ownership', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->agent, 'api')->postJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/owner', ['name' => 'Owner', 'email' => 'owner@example.test'])->assertOk();
    expect($this->vendor->users()->wherePivot('is_primary', true)->count())->toBe(0);
    $owner = User::where('email', 'owner@example.test')->firstOrFail();
    expect($owner->type)->toBe('owner');
    expect(DB::table('vendor_invitations')->where('user_id', $owner->id)->value('assign_owner'))->toBe(1);
});
it('prevents owner replacement', function () {
    $owner = User::create(['name' => 'Existing owner', 'email' => 'owner@example.test', 'password' => 'test-password-2026', 'type' => 'owner']);
    $this->vendor->users()->attach($owner->id, ['is_primary' => true, 'is_active' => true]);
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->agent, 'api')->postJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/owner', ['name' => 'Replacement', 'email' => 'replacement@example.test'])->assertConflict();
});

it('accepts the owner invitation and requires Operations readiness before handover', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->agent, 'api')->postJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/owner', ['name' => 'Owner', 'email' => 'owner@example.test'])->assertOk();
    $owner = User::where('email', 'owner@example.test')->firstOrFail();
    $token = null;
    Notification::assertSentTo($owner, \App\Notifications\VendorInvitation::class, function ($n) use ($owner, &$token) {
        $url = $n->toMail($owner)->viewData['acceptUrl'];
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $token = $q['token'];

        return true;
    });
    $this->postJson('/v1/vendor-invitations/accept', ['token' => $token, 'password' => 'Owner-password-2026', 'password_confirmation' => 'Owner-password-2026'])->assertOk();
    expect((bool) $this->vendor->users()->whereKey($owner->id)->first()->pivot->is_primary)->toBeTrue();
    expect($owner->fresh()->email_verified_at)->not->toBeNull();
    expect((bool) $this->agent->vendors()->whereKey($this->vendor->id)->first()->pivot->is_primary)->toBeFalse();
    $this->patchJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/progress', ['stage' => 'handed_over'])->assertForbidden();
    $this->actingAs($this->admin, 'api')->patchJson('/v1/operations/onboarding-agents/'.$this->agent->uuid.'/assignments/'.$this->vendor->uuid.'/progress', ['stage' => 'ready'])->assertOk();
    $this->actingAs($this->agent, 'api')->patchJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/progress', ['stage' => 'handed_over'])->assertOk();
    expect(DB::table('onboarding_audits')->where('action', 'owner.accepted')->exists())->toBeTrue();
});
it('invalidates old invitation links when resent and blocks disabled invitation acceptance', function () {
    $this->agent->update(['email_verified_at' => null]);
    $service = app(OnboardingAgentService::class);
    $service->invite($this->agent);
    $old = DB::table('agent_invitations')->where('user_id', $this->agent->id)->value('token_hash');
    $service->invite($this->agent);
    expect(DB::table('agent_invitations')->where('token_hash', $old)->exists())->toBeFalse();
    $token = null;
    Notification::assertSentTo($this->agent, OnboardingAgentInvitation::class, function ($n) use (&$token) {
        parse_str(parse_url($n->url, PHP_URL_QUERY), $q);
        $token = $q['token'];

        return true;
    });
    $this->agent->update(['is_active' => false]);
    $this->postJson('/v1/agent-invitations/accept', ['token' => $token, 'password' => 'New-password-2026', 'password_confirmation' => 'New-password-2026'])->assertStatus(403);
});
it('lets agents create menus and service areas only for assigned businesses', function () {
    $one = onboardingBusiness($this->vendor, 'Allowed');
    $two = onboardingBusiness($this->vendor, 'Sibling');
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'selected_businesses', [$one->id]);
    $this->actingAs($this->agent, 'api')->postJson('/v1/businesses/'.$one->uuid.'/menu-management/categories', ['name' => 'Breakfast', 'is_active' => true])->assertCreated();
    $this->postJson('/v1/businesses/'.$two->uuid.'/menu-management/categories', ['name' => 'Breakfast', 'is_active' => true])->assertForbidden();
    $this->postJson('/v1/businesses/'.$one->uuid.'/sections', ['name' => 'Main floor', 'is_active' => true])->assertCreated();
    $this->getJson('/v1/businesses/'.$one->uuid.'/orders')->assertForbidden();
    $this->postJson('/v1/operations/businesses/'.$one->uuid.'/payment-methods/any/review', [])->assertForbidden();
});
it('restricts selected assignments from inviting owners or managing the entire vendor', function () {
    $one = onboardingBusiness($this->vendor, 'Allowed');
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'selected_businesses', [$one->id]);
    $this->actingAs($this->agent, 'api')->postJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/owner', ['name' => 'Owner', 'email' => 'owner@example.test'])->assertNotFound();
    $this->putJson('/v1/vendors/'.$this->vendor->uuid, ['name' => 'Changed vendor'])->assertForbidden();
});
it('shows assigned agents on Operations business details without sibling assignments', function () {
    $one = onboardingBusiness($this->vendor, 'Allowed');
    $two = onboardingBusiness($this->vendor, 'Sibling');
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'selected_businesses', [$one->id]);
    $this->actingAs($this->admin, 'api')->getJson('/v1/operations/businesses/'.$one->uuid.'/onboarding-agents')->assertOk()->assertJsonCount(1, 'data.agents');
    $this->getJson('/v1/operations/businesses/'.$two->uuid.'/onboarding-agents')->assertOk()->assertJsonCount(0, 'data.agents');
});
it('does not let agents perform Operations actions with an owner account', function () {
    $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Owner-password-2026', 'type' => 'owner']);
    $this->actingAs($owner, 'api')->getJson('/v1/operations/onboarding-agents')->assertForbidden();
});

it('creates a business with its contact details and keeps vendor ownership separate', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->agent, 'api')->postJson('/v1/businesses/'.$this->vendor->uuid, ['district_id' => 1, 'business_type_id' => 1, 'tin' => 'TIN-NEW-BIZ', 'name' => 'New venue', 'location' => 'Dar', 'contacts' => [['contact' => '0712345678']]])->assertCreated()->assertJsonCount(1, 'data.business.contacts');
    expect($this->vendor->users()->wherePivot('is_primary', true)->count())->toBe(0);
    expect(DB::table('business_user')->where('user_id', $this->agent->id)->count())->toBe(0);
    $this->getJson('/v1/onboarding-agent')->assertOk()->assertJsonCount(1, 'data.vendors.0.businesses');
});
it('allows receiving payment setup submission while keeping it pending Operations review', function () {
    $business = onboardingBusiness($this->vendor, 'Payment setup');
    $method = \App\Models\Payment\PaymentMethod::create(['name' => 'Bank transfer', 'code' => 'bank_transfer', 'is_active' => true]);
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->agent, 'api')->putJson('/v1/businesses/'.$business->uuid.'/payment-methods', ['submit_for_verification' => true, 'methods' => [['payment_method_id' => $method->id, 'is_enabled' => true, 'accounts' => [['bank_name' => 'Test bank', 'account_number' => '1234567890', 'account_holder_name' => 'Vendor Ltd', 'currency' => 'TZS']]]]])->assertOk();
    expect(DB::table('business_payment_method_accounts')->value('status'))->not->toBe('active');
    $this->postJson('/v1/operations/businesses/'.$business->uuid.'/payment-methods/any/review', ['decision' => 'approved'])->assertForbidden();
});
it('refuses handover until an owner has accepted', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->admin, 'api')->patchJson('/v1/operations/onboarding-agents/'.$this->agent->uuid.'/assignments/'.$this->vendor->uuid.'/progress', ['stage' => 'ready'])->assertOk();
    $this->actingAs($this->agent, 'api')->patchJson('/v1/onboarding-agent/vendors/'.$this->vendor->uuid.'/progress', ['stage' => 'handed_over'])->assertUnprocessable();
});

it('paginates and searches the agent directory on the server', function () {
    for ($i = 1; $i <= 16; $i++) {
        User::create(['name' => 'Directory Agent '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => 'directory'.$i.'@gmail.com', 'type' => 'agent', 'password' => 'Test-only-password-2026', 'is_active' => true]);
    }
    $this->actingAs($this->admin, 'api')->getJson('/v1/operations/onboarding-agents?per_page=15&page=1')
        ->assertOk()->assertJsonCount(15, 'data.agents')->assertJsonPath('data.total', 17)->assertJsonPath('data.per_page', 15)->assertJsonPath('data.from', 1)->assertJsonPath('data.to', 15)->assertJsonPath('data.last_page', 2);
    $this->getJson('/v1/operations/onboarding-agents?per_page=15&page=2')
        ->assertOk()->assertJsonCount(2, 'data.agents')->assertJsonPath('data.from', 16)->assertJsonPath('data.to', 17)->assertJsonPath('data.page', 2);
    $this->getJson('/v1/operations/onboarding-agents?search=DIRECTORY16%40GMAIL.COM&per_page=10')
        ->assertOk()->assertJsonCount(1, 'data.agents')->assertJsonPath('data.total', 1)->assertJsonPath('data.agents.0.email', 'directory16@gmail.com');
});

it('returns zero-result pagination metadata for an empty agent table', function () {
    $this->agent->delete();
    $this->actingAs($this->admin, 'api')->getJson('/v1/operations/onboarding-agents?per_page=10')
        ->assertOk()->assertJsonCount(0, 'data.agents')->assertJsonPath('data.total', 0)->assertJsonPath('data.last_page', 1)->assertJsonPath('data.from', null)->assertJsonPath('data.to', null);
});

it('validates agent directory pagination sizes and page numbers', function () {
    $this->actingAs($this->admin, 'api')->getJson('/v1/operations/onboarding-agents?per_page=999')->assertUnprocessable();
    $this->getJson('/v1/operations/onboarding-agents?page=0')->assertUnprocessable();
    $this->getJson('/v1/operations/onboarding-agents?per_page=15')->assertOk()->assertJsonPath('data.per_page', 15);
});

it('logs activation links immediately for local development without a queue worker', function () {
    app()->detectEnvironment(fn () => 'local');
    $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('info')->once()->with('Onboarding agent activation invitation', \Mockery::on(function ($context) {
        parse_str(parse_url($context['activation_url'], PHP_URL_QUERY), $query);

        return $context['email'] === $this->agent->email
            && DB::table('agent_invitations')->where('user_id', $this->agent->id)->value('token_hash') === hash('sha256', $query['token']);
    }));
    \Illuminate\Support\Facades\Log::shouldReceive('build')->once()->with(['driver' => 'single', 'path' => storage_path('logs/agent-invitations.log'), 'level' => 'info'])->andReturn($logger);
    app(OnboardingAgentService::class)->invite($this->agent);
});

it('does not log activation secrets outside local development', function () {
    app()->detectEnvironment(fn () => 'production');
    \Illuminate\Support\Facades\Log::shouldReceive('build')->never();
    app(OnboardingAgentService::class)->invite($this->agent);
    Notification::assertSentTo($this->agent, OnboardingAgentInvitation::class);
});

it('stores agent phone numbers with country codes on create and update', function () {
    Country::create(['name' => 'Kenya', 'iso2' => 'KE', 'iso3' => 'KEN', 'currency' => 'KES', 'phone_code' => '254', 'flag' => 'flags/ke.jpg']);
    $this->actingAs($this->admin, 'api')->postJson('/v1/operations/onboarding-agents', ['name' => 'Phone Agent', 'email' => 'phone-agent@gmail.com', 'phone' => '+255 712 345 678'])
        ->assertCreated()->assertJsonPath('data.agent.phone', '255712345678');
    $agent = User::where('email', 'phone-agent@gmail.com')->firstOrFail();
    $this->putJson('/v1/operations/onboarding-agents/'.$agent->uuid, ['name' => 'Phone Agent', 'phone' => '+254712345678'])
        ->assertOk()->assertJsonPath('data.agent.phone', '254712345678');
});

it('rejects local agent numbers without a country code with a validation error', function () {
    $this->actingAs($this->admin, 'api')->postJson('/v1/operations/onboarding-agents', ['name' => 'Phone Agent', 'email' => 'phone-agent@gmail.com', 'phone' => '0712345678'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
    $this->putJson('/v1/operations/onboarding-agents/'.$this->agent->uuid, ['name' => 'Agent', 'phone' => '712345678'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');
});

it('uses the same eight-character password minimum as other account flows', function () {
    $token = 'test-agent-password-policy-token';
    DB::table('agent_invitations')->insert(['user_id' => $this->agent->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/v1/agent-invitations/accept', ['token' => $token, 'password' => 'Test123', 'password_confirmation' => 'Test123'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson('/v1/agent-invitations/accept', ['token' => $token, 'password' => 'Test1234', 'password_confirmation' => 'Test1234'])->assertOk();
});

it('paginates agent workspace assignments on the server', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    for ($i = 1; $i <= 16; $i++) {
        $vendor = Vendor::create(['country_id' => $this->country->id, 'name' => 'Workspace Vendor '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'email' => 'workspace'.$i.'@example.test']);
        app(OnboardingAgentService::class)->assign($this->agent, $vendor, 'all_businesses', []);
    }
    $this->actingAs($this->agent, 'api')->getJson('/v1/onboarding-agent?per_page=15&page=1')->assertOk()->assertJsonCount(15, 'data.vendors')->assertJsonPath('data.meta.total', 17)->assertJsonPath('data.meta.from', 1)->assertJsonPath('data.meta.to', 15)->assertJsonPath('data.meta.last_page', 2);
    $this->getJson('/v1/onboarding-agent?per_page=15&page=2')->assertOk()->assertJsonCount(2, 'data.vendors')->assertJsonPath('data.meta.current_page', 2)->assertJsonPath('data.meta.from', 16);
    $this->getJson('/v1/onboarding-agent?per_page=999')->assertUnprocessable();
});

it('searches only vendors and businesses assigned to the agent', function () {
    $allowed = onboardingBusiness($this->vendor, 'Allowed Room');
    onboardingBusiness($this->vendor, 'Hidden Room');
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'selected_businesses', [$allowed->id]);
    $this->actingAs($this->agent, 'api')->getJson('/v1/onboarding-agent?search=ALLOWED')->assertOk()->assertJsonCount(1, 'data.vendors')->assertJsonCount(1, 'data.vendors.0.businesses');
    $this->getJson('/v1/onboarding-agent?search=Hidden')->assertOk()->assertJsonCount(0, 'data.vendors')->assertJsonPath('data.meta.total', 0);
    $this->getJson('/v1/onboarding-agent?search=Other')->assertOk()->assertJsonCount(0, 'data.vendors');
    $this->getJson('/v1/onboarding-agent?search=Assigned')->assertOk()->assertJsonCount(1, 'data.vendors');
});

it('returns empty table metadata when the agent has no assignments', function () {
    $this->actingAs($this->agent, 'api')->getJson('/v1/onboarding-agent?per_page=10')->assertOk()->assertJsonCount(0, 'data.vendors')->assertJsonPath('data.meta.total', 0)->assertJsonPath('data.meta.from', null)->assertJsonPath('data.meta.to', null)->assertJsonPath('data.meta.last_page', 1);
});

it('allows compliance uploads with either date or both dates omitted', function (array $dates) {
    \Illuminate\Support\Facades\Storage::fake('local');
    $type = \App\Models\Business\ComplianceDocumentType::create(['key' => 'optional_dates', 'name' => 'Optional dates document', 'scope' => 'vendor', 'is_active' => true, 'requires_expiry_date' => true]);
    $type->rules()->create(['is_active' => true, 'required_for_payment_activation' => false]);
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $this->actingAs($this->agent, 'api')->post('/v1/vendors/'.$this->vendor->uuid.'/documents', ['document_type' => $type->key, 'file' => \Illuminate\Http\UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')] + $dates, ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.document.issued_at', $dates['issued_at'] ?? null)->assertJsonPath('data.document.expires_at', $dates['expires_at'] ?? null);
})->with([
    'neither date' => [[]],
    'issue date only' => [['issued_at' => '2026-01-01']],
    'expiry date only' => [['expires_at' => '2030-01-01']],
]);

it('allows clearing compliance dates and still rejects reversed dates', function () {
    app(OnboardingAgentService::class)->assign($this->agent, $this->vendor, 'all_businesses', []);
    $type = \App\Models\Business\ComplianceDocumentType::create(['key' => 'optional_dates', 'name' => 'Optional dates document', 'scope' => 'vendor', 'is_active' => true, 'requires_expiry_date' => true]);
    $document = \App\Models\Business\ComplianceDocument::create(['vendor_id' => $this->vendor->id, 'compliance_document_type_id' => $type->id, 'document_type' => $type->key, 'document_type_name' => $type->name, 'original_filename' => 'document.pdf', 'disk' => 'local', 'storage_path' => 'fake/document.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => 'needs_update', 'issued_at' => '2026-01-01', 'expires_at' => '2030-01-01', 'uploaded_by' => $this->agent->id]);
    $url = '/v1/vendors/'.$this->vendor->uuid.'/documents/'.$document->uuid.'/update';
    $this->actingAs($this->agent, 'api')->postJson($url, ['issued_at' => null, 'expires_at' => null])->assertOk()->assertJsonPath('data.document.issued_at', null)->assertJsonPath('data.document.expires_at', null);
    $document->refresh()->update(['status' => 'needs_update']);
    $this->postJson($url, ['issued_at' => '2030-01-01', 'expires_at' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('expires_at');
});
