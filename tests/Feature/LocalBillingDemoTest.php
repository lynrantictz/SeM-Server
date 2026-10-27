<?php

use App\Models\Business\BusinessInvoice;
use App\Models\Business\OrderPlatformFee;
use App\Models\Business\Vendor;
use App\Services\Business\BusinessBillingService;
use App\Services\Business\LocalBillingDemo;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-10-27 09:00:00 UTC');
    CarbonImmutable::setTestNow('2026-10-27 09:00:00 UTC');
    Storage::fake('public');
    Storage::fake('local');
    Notification::fake();
    $this->seed([\Database\Seeders\LocationSeeder::class, \Database\Seeders\BusinessTypeSeeder::class, \Database\Seeders\OrderStatusSeeder::class, \Database\Seeders\PaymentStatusSeeder::class, \Database\Seeders\PaymentMethodSeeder::class, \Database\Seeders\OrderingChannelSeeder::class, \Database\Seeders\ComplianceDocumentTypeSeeder::class]);
});
afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});
it('creates exactly ten discoverable local demo businesses and preserves unrelated records', function () {
    $country = \App\Models\Location\Country::where('iso2', 'TZ')->first();
    $existing = Vendor::withoutEvents(fn () => Vendor::forceCreate(['uuid' => (string) Illuminate\Support\Str::uuid(), 'name' => 'Existing untouched', 'email' => 'untouched@example.test', 'country_id' => $country->id]));
    $before = $existing->fresh()->getRawOriginal();
    $demo = app(LocalBillingDemo::class);
    $manifest = $demo->seed();
    expect($manifest['businesses'])->toHaveCount(10);
    expect(DB::table('orders')->count())->toBe(1000);
    expect(DB::table('payments')->count())->toBe(1000);
    expect(OrderPlatformFee::count())->toBe(1000);
    expect($existing->fresh()->getRawOriginal())->toBe($before);
    expect(app(\App\Services\Discovery\DiscoverableBusinesses::class)->query()->count())->toBe(10);
    $rows = $demo->status()['businesses'];
    expect($rows->where('restricted', true)->count())->toBe(2);
    expect($rows->pluck('orders')->unique()->all())->toBe([100]);
    $businesses = $demo->businesses()->orderBy('id')->get();
    expect(app(BusinessBillingService::class)->restricted($businesses[1]))->toBeTrue();
    expect(app(BusinessBillingService::class)->restricted($businesses[2]))->toBeTrue();
    expect(fn () => app(BusinessBillingService::class)->assertCanOrder($businesses[1]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    app(BusinessBillingService::class)->assertCanOrder($businesses[0]);
    expect(app(BusinessBillingService::class)->restricted($businesses[9]))->toBeFalse();
    expect(OrderPlatformFee::where('business_id', $businesses[7]->id)->sum('amount'))->toEqual(0);
    expect(OrderPlatformFee::where('business_id', $businesses[5]->id)->sum('amount'))->toEqual(0);
    expect(OrderPlatformFee::where('business_id', $businesses[8]->id)->distinct()->pluck('rate'))->toHaveCount(2);
    $before = BusinessInvoice::count();
    $result = $demo->run();
    expect(BusinessInvoice::count())->toBeGreaterThan($before);
    expect($result['runs'][0]['errors'])->toBe([]);
    $total = BusinessInvoice::count();
    $demo->run();
    expect(BusinessInvoice::count())->toBe($total);
    expect(fn () => $demo->seed())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('orders')->count())->toBe(1000);
});
it('refuses to seed in production', function () {
    app()->instance('env', 'production');
    expect(fn () => app(LocalBillingDemo::class)->seed())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('orders')->count())->toBe(0);
});

it('requires Operations finance access and keeps the tools unavailable in production', function () {
    $user = \App\Models\Auth\User::withoutEvents(fn () => \App\Models\Auth\User::forceCreate(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Demo reader', 'email' => 'demo-reader@example.test', 'password' => 'Example@2026', 'type' => 'paperstic', 'is_active' => true]));
    $this->actingAs($user, 'api')->getJson('/v1/operations/billing/demo')->assertForbidden();
    foreach (['operations.businesses.view', 'operations.settlements.view'] as $permission) {
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($permission, 'api'));
    }
    $this->getJson('/v1/operations/billing/demo')->assertOk()->assertJsonPath('data.seeded', false);
    $this->postJson('/v1/operations/billing/demo/run')->assertForbidden();
    app()->instance('env', 'production');
    $this->getJson('/v1/operations/billing/demo')->assertForbidden();
});
