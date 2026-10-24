<?php

use App\Models\Auth\User;
use App\Models\Business\ComplianceDocument;
use App\Models\Business\Vendor;
use App\Models\Location\Country;
use App\Services\Operations\RecentActivity;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    foreach (['operations.audit.view', 'operations.kyc.view'] as $permission) {
        Permission::findOrCreate($permission, 'api');
    }
    $this->operator = User::create(['name' => 'Operations Reviewer', 'email' => 'activity@example.test', 'password' => 'Example@2026', 'type' => 'paperstic', 'is_active' => true]);
    $this->operator->givePermissionTo(['operations.audit.view', 'operations.kyc.view']);
    $country = Country::create(['name' => 'Tanzania', 'iso2' => 'TZ', 'iso3' => 'TZA', 'currency' => 'TZS', 'phone_code' => '255', 'flag' => 'flags/tz.jpg']);
    $this->vendor = Vendor::create(['name' => 'Example vendor', 'country_id' => $country->id, 'email' => 'vendor@example.test']);
});
it('describes a compliance submission with its vendor, actor and accessible review destination', function () {
    $document = ComplianceDocument::create(['vendor_id' => $this->vendor->id, 'document_type' => 'tin', 'document_type_name' => 'TIN certificate', 'original_filename' => 'tin.pdf', 'disk' => 'local', 'storage_path' => 'private/tin.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'status' => 'pending']);
    Audit::create(['auditable_type' => ComplianceDocument::class, 'auditable_id' => $document->id, 'event' => 'created', 'user_type' => User::class, 'user_id' => $this->operator->id, 'new_values' => ['status' => 'pending', 'account_number' => 'SECRET-ACCOUNT'], 'old_values' => []]);
    $activity = app(RecentActivity::class)->forUser($this->operator)->first();
    expect($activity['title'])->toBe('TIN certificate submitted');
    expect($activity['subject'])->toBe('Example vendor');
    expect($activity['actor'])->toBe('Operations Reviewer');
    expect($activity['url'])->toBe('/kyc/vendor/'.$this->vendor->uuid);
    expect($activity['status'])->toBe('pending');
    expect(json_encode($activity))->not->toContain('SECRET-ACCOUNT', 'private/tin.pdf');
});
it('keeps vendor names useful after deletion and avoids broken record links', function () {
    Audit::create(['auditable_type' => Vendor::class, 'auditable_id' => $this->vendor->id, 'event' => 'deleted', 'old_values' => ['name' => 'Removed vendor'], 'new_values' => []]);
    $this->vendor->delete();
    $activity = app(RecentActivity::class)->forUser($this->operator)->first();
    expect($activity['title'])->toBe('Vendor removed');
    expect($activity['subject'])->toBe('Removed vendor');
    expect($activity['actor'])->toBe('System');
    expect($activity['url'])->toBeNull();
});
it('returns the latest thirty entries in stable order and respects audit and destination permissions', function () {
    foreach (range(1, 31) as $index) {
        Audit::create(['auditable_type' => Vendor::class, 'auditable_id' => $this->vendor->id, 'event' => 'updated', 'old_values' => [], 'new_values' => ['name' => 'Revision '.$index]]);
    }
    $activities = app(RecentActivity::class)->forUser($this->operator);
    expect($activities)->toHaveCount(30);
    expect($activities->pluck('id')->all())->toBe($activities->pluck('id')->sortDesc()->values()->all());
    $this->operator->revokePermissionTo('operations.kyc.view');
    expect(app(RecentActivity::class)->forUser($this->operator->fresh())->first()['url'])->toBeNull();
    $this->operator->revokePermissionTo('operations.audit.view');
    expect(app(RecentActivity::class)->forUser($this->operator->fresh()))->toHaveCount(0);
});
