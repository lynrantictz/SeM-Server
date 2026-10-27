<?php

use App\Models\Auth\User;
use App\Models\Business\Business;
use App\Http\Controllers\Api\V1\Business\BusinessOrderController;
use App\Services\Business\BusinessProfileAccess;
use App\Services\Business\VendorBusinessAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function vendorAccessFixture(string $userType = 'vendor'): array
{
    $now = now();
    $userId = DB::table('users')->insertGetId([
        'name' => 'Access test user',
        'email' => Str::uuid().'@paperstic.test',
        'password' => bcrypt('password'),
        'type' => $userType,
        'is_active' => true,
        'email_verified_at' => $now,
        'uuid' => (string) Str::uuid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $vendorId = DB::table('vendors')->insertGetId([
        'country_id' => 1,
        'name' => 'Access test vendor',
        'email' => Str::uuid().'@paperstic.test',
        'uuid' => (string) Str::uuid(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $makeBusiness = function (string $suffix) use ($vendorId, $now): int {
        return DB::table('businesses')->insertGetId([
            'vendor_id' => $vendorId,
            'district_id' => 1,
            'business_type_id' => 1,
            'name' => 'Access test business '.$suffix,
            'location' => 'Dar es Salaam',
            'order_prefix' => 'AT'.Str::upper(Str::random(6)),
            'code_prefix' => 'AC'.Str::upper(Str::random(6)),
            'uuid' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };

    return [
        User::query()->findOrFail($userId),
        $vendorId,
        Business::query()->findOrFail($makeBusiness('one')),
        Business::query()->findOrFail($makeBusiness('two')),
    ];
}

it('activates the accepted owner membership and grants vendor business access on SQLite', function () {
    [$user, $vendorId, $business] = vendorAccessFixture('owner');
    $token = Str::random(48);
    DB::table('vendor_user')->insert([
        'vendor_id' => $vendorId,
        'user_id' => $user->id,
        'is_primary' => false,
        'role' => 'manager',
        'access_scope' => 'all_businesses',
        'is_active' => false,
        'invited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('vendor_invitations')->insert([
        'vendor_id' => $vendorId,
        'user_id' => $user->id,
        'token' => hash('sha256', $token),
        'requires_password_setup' => false,
        'assign_owner' => true,
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/v1/vendor-invitations/accept', ['token' => $token])->assertOk();

    $membership = DB::table('vendor_user')->where('vendor_id', $vendorId)->where('user_id', $user->id)->first();
    expect((bool) $membership->is_active)->toBeTrue()
        ->and((bool) $membership->is_primary)->toBeTrue()
        ->and($membership->role)->toBe('owner')
        ->and($membership->access_scope)->toBe('all_businesses')
        ->and(app(VendorBusinessAccess::class)->roleFor($user->fresh(), $business))->toBe('owner');
});

it('enforces vendor manager and viewer scope and denies inactive access', function () {
    [$manager, $vendorId, $assignedBusiness, $otherBusiness] = vendorAccessFixture();
    DB::table('vendor_user')->insert([
        'vendor_id' => $vendorId, 'user_id' => $manager->id, 'is_primary' => false,
        'role' => 'manager', 'access_scope' => 'all_businesses', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(app(VendorBusinessAccess::class)->roleFor($manager, $otherBusiness))->toBe('vendor_manager');

    $manager->vendors()->updateExistingPivot($vendorId, ['role' => 'viewer', 'access_scope' => 'selected_businesses']);
    $manager->businesses()->attach($assignedBusiness->id, [
        'title' => 'viewer-assignment-'.$manager->id,
        'business_role' => 'vendor_manager',
        'is_active' => true,
        'activated_at' => now(),
    ]);
    expect(app(VendorBusinessAccess::class)->roleFor($manager, $assignedBusiness))->toBe('vendor_viewer')
        ->and(app(VendorBusinessAccess::class)->roleFor($manager, $otherBusiness))->toBeNull();

    $this->actingAs($manager);
    $profilePermissions = app(BusinessProfileAccess::class)->permissions($assignedBusiness);
    expect($profilePermissions['can_view_profile'])->toBeFalse()
        ->and($profilePermissions['can_manage_operations'])->toBeFalse();

    $manager->vendors()->updateExistingPivot($vendorId, ['is_active' => false]);
    expect(app(VendorBusinessAccess::class)->roleFor($manager, $assignedBusiness))->toBeNull();
});

it('allows a vendor viewer to read orders but not perform order actions', function () {
    $controller = new BusinessOrderController;

    expect((new ReflectionMethod($controller, 'canSeeTab'))->invoke($controller, 'vendor_viewer', 'awaiting'))->toBeTrue()
        ->and((new ReflectionMethod($controller, 'canCreateOrEdit'))->invoke($controller, 'vendor_viewer'))->toBeFalse()
        ->and((new ReflectionMethod($controller, 'canGeneratePaymentQr'))->invoke($controller, 'vendor_viewer'))->toBeFalse();
});
